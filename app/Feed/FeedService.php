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
use GuzzleHttp\Psr7\Request;
use RuntimeException;

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

    /**
     * putRecord replaces the whole record, so anything not sent here is gone
     * afterwards. Without FEEDGEN_AVATAR set, republishing removes an avatar
     * the feed already had.
     */
    public function publish(): PutRecordOutput
    {
        $record = Generator::new(
            // The service DID, not the publisher's: this is where the AppView
            // sends getFeedSkeleton.
            did: $this->config->serviceDid,
            displayName: $this->config->displayName,
            description: $this->config->description,
            createdAt: new DateTimeImmutable(),
        )->toArray();

        if ($this->config->avatarPath !== null) {
            $record['avatar'] = $this->uploadAvatar($this->config->avatarPath);
        }

        return $this->metaClient
            ->comAtprotoRepoPutRecord()
            // The record lives on the publisher's PDS. Left at libphpsky's
            // default, an account hosted anywhere but bsky.social gets its
            // write sent to the wrong server.
            ->withEndpoint($this->config->pdsUrl)
            ->procedure(PutRecordInput::new(
                repo: $this->config->publisherDid,
                collection: Generator::ID,
                rkey: $this->config->recordKey,
                record: $record,
                validate: true,
            ));
    }

    public function unpublish(): DeleteRecordOutput
    {
        return $this->metaClient
            ->comAtprotoRepoDeleteRecord()
            ->withEndpoint($this->config->pdsUrl)
            ->procedure(DeleteRecordInput::new(
                repo: $this->config->publisherDid,
                collection: Generator::ID,
                rkey: $this->config->recordKey,
            ));
    }

    /**
     * libphpsky's uploadBlob takes no body, so the image goes up as a raw
     * request through the same authenticated client instead.
     *
     * @return array<string, mixed> the blob reference to put in the record
     */
    private function uploadAvatar(string $path): array
    {
        $mimeType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => throw new RuntimeException("Avatar must be a PNG or JPEG: {$path}"),
        };

        $image = @file_get_contents($path);

        if ($image === false) {
            throw new RuntimeException("Cannot read avatar: {$path}");
        }

        $response = $this->metaClient->getClient()->sendRequest(new Request(
            'POST',
            rtrim($this->config->pdsUrl, '/') . '/xrpc/com.atproto.repo.uploadBlob',
            ['Content-Type' => $mimeType],
            $image,
        ));

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() >= 300 || !is_array($body) || !is_array($body['blob'] ?? null)) {
            throw new RuntimeException("Avatar upload failed with HTTP {$response->getStatusCode()}");
        }

        return $body['blob'];
    }

    private static function parseCursor(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '' || !ctype_digit($cursor)) {
            return null;
        }

        return (int) $cursor;
    }
}
