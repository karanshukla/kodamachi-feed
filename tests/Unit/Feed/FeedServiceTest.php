<?php

declare(strict_types=1);

namespace Tests\Unit\Feed;

use Aazsamir\Libphpsky\Model\Meta\ATProtoMetaClient;
use App\Feed\FeedConfig;
use App\Feed\FeedService;
use App\Post\FeedPost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryFeedPostRepository;

/**
 * @internal
 */
final class FeedServiceTest extends TestCase
{
    private InMemoryFeedPostRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryFeedPostRepository();
    }

    #[Test]
    public function describes_the_feed_it_serves(): void
    {
        $described = $this->service()->describeFeed()->toArray();

        self::assertSame('did:web:feed.test', $described['did']);
        self::assertSame(
            'at://did:plc:publisher/app.bsky.feed.generator/kodamachi',
            $described['feeds'][0]['uri'],
        );
    }

    #[Test]
    public function returns_newest_posts_first(): void
    {
        $this->givenPosts(['a' => 3000, 'b' => 1000, 'c' => 2000]);

        $feed = $this->service()->getFeed()->toArray();

        self::assertSame(
            ['at://did:plc:author/app.bsky.feed.post/a', 'at://did:plc:author/app.bsky.feed.post/c', 'at://did:plc:author/app.bsky.feed.post/b'],
            array_column($feed['feed'], 'post'),
        );
    }

    #[Test]
    public function pages_through_the_feed_with_the_cursor_it_returns(): void
    {
        $this->givenPosts(['a' => 3000, 'b' => 2000, 'c' => 1000]);
        $service = $this->service();

        $first = $service->getFeed(limit: 2)->toArray();
        self::assertCount(2, $first['feed']);
        self::assertSame('2000', $first['cursor']);

        $second = $service->getFeed(limit: 2, cursor: $first['cursor'])->toArray();

        self::assertSame(
            ['at://did:plc:author/app.bsky.feed.post/c'],
            array_column($second['feed'], 'post'),
        );
    }

    #[Test]
    public function returns_no_cursor_when_the_feed_is_empty(): void
    {
        $feed = $this->service()->getFeed()->toArray();

        self::assertSame([], $feed['feed']);
        self::assertArrayNotHasKey('cursor', $feed);
    }

    /**
     * A hand-rolled client can send anything. A cursor that is not a number is
     * treated as no cursor rather than as an error, because the alternative is
     * a client that can never load the feed at all.
     */
    #[Test]
    public function ignores_a_cursor_that_is_not_a_timestamp(): void
    {
        $this->givenPosts(['a' => 1000]);

        self::assertCount(1, $this->service()->getFeed(cursor: 'not-a-number')->toArray()['feed']);
        self::assertCount(1, $this->service()->getFeed(cursor: '')->toArray()['feed']);
    }

    #[Test]
    public function clamps_the_limit_to_what_the_lexicon_allows(): void
    {
        $this->givenPosts(array_combine(
            array_map(static fn (int $i): string => "post{$i}", range(1, 120)),
            range(1, 120),
        ));

        self::assertCount(100, $this->service()->getFeed(limit: 500)->toArray()['feed']);
        self::assertCount(1, $this->service()->getFeed(limit: 0)->toArray()['feed']);
        self::assertCount(50, $this->service()->getFeed()->toArray()['feed']);
    }

    /**
     * @param array<string, int> $posts rkey => indexedAt
     */
    private function givenPosts(array $posts): void
    {
        foreach ($posts as $rkey => $indexedAt) {
            $this->repository->save(new FeedPost(
                uri: "at://did:plc:author/app.bsky.feed.post/{$rkey}",
                cid: "cid-{$rkey}",
                indexedAt: $indexedAt,
            ));
        }
    }

    private function service(): FeedService
    {
        return new FeedService(
            new FeedConfig(
                hostname: 'feed.test',
                serviceDid: 'did:web:feed.test',
                publisherDid: 'did:plc:publisher',
                textTerms: ['kodamachi'],
                altTerms: ['kodamachi'],
            ),
            $this->repository,
            ATProtoMetaClient::default(),
        );
    }
}
