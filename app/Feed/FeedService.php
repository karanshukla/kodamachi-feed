<?php

declare(strict_types=1);

namespace App\Feed;

use Aazsamir\Libphpsky\Model\App\Bsky\Feed\DescribeFeedGenerator\DescribeFeedGeneratorOutput;
use Aazsamir\Libphpsky\Model\App\Bsky\Feed\DescribeFeedGenerator\Feed as DescribedFeed;
use Aazsamir\Libphpsky\Model\App\Bsky\Feed\Generator\Generator;
use Aazsamir\Libphpsky\Model\App\Bsky\Feed\GetFeedSkeleton\GetFeedSkeletonOutput;
use Aazsamir\Libphpsky\Model\Com\Atproto\Repo\DeleteRecord\DeleteRecordInput;
use Aazsamir\Libphpsky\Model\Com\Atproto\Repo\DeleteRecord\DeleteRecordOutput;
use Aazsamir\Libphpsky\Model\Com\Atproto\Repo\PutRecord\PutRecordInput;
use Aazsamir\Libphpsky\Model\Com\Atproto\Repo\PutRecord\PutRecordOutput;
use Aazsamir\Libphpsky\Model\Meta\ATProtoMetaClient;
use App\Post\FeedPost;
use App\Post\FeedPostRepository;
use DateTimeImmutable;

final readonly class FeedService
{
    /**
     * The default the app.bsky.feed.getFeedSkeleton lexicon declares. The
     * AppView normally sends an explicit limit, but a hand-rolled client need
     * not.
     */
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 100;

    public function __construct(
        private FeedConfig $config,
        private FeedPostRepository $repository,
        private ATProtoMetaClient $metaClient,
    ) {}

    public function describeFeed(): DescribeFeedGeneratorOutput
    {
        return DescribeFeedGeneratorOutput::new(
            did: $this->config->serviceDid,
            feeds: [
                DescribedFeed::new(uri: $this->config->feedUri()),
            ],
        );
    }

    public function getFeed(?int $limit = null, ?string $cursor = null): GetFeedSkeletonOutput
    {
        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));
        $posts = $this->repository->search($limit, self::parseCursor($cursor));
        $last = end($posts);

        return GetFeedSkeletonOutput::new(
            feed: array_map(static fn (FeedPost $post) => $post->toSkeleton(), $posts),
            cursor: $last === false ? null : (string) $last->indexedAt,
        );
    }

    public function publish(): PutRecordOutput
    {
        return $this->metaClient
            ->comAtprotoRepoPutRecord()
            ->procedure(PutRecordInput::new(
                repo: $this->config->publisherDid,
                collection: Generator::ID,
                rkey: $this->config->recordKey,
                record: Generator::new(
                    // The service DID, not the publisher's: this is where the
                    // AppView sends getFeedSkeleton.
                    did: $this->config->serviceDid,
                    displayName: $this->config->displayName,
                    description: $this->config->description,
                    createdAt: new DateTimeImmutable(),
                ),
                validate: true,
            ));
    }

    public function unpublish(): DeleteRecordOutput
    {
        return $this->metaClient
            ->comAtprotoRepoDeleteRecord()
            ->procedure(DeleteRecordInput::new(
                repo: $this->config->publisherDid,
                collection: Generator::ID,
                rkey: $this->config->recordKey,
            ));
    }

    private static function parseCursor(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '' || !ctype_digit($cursor)) {
            return null;
        }

        return (int) $cursor;
    }
}
