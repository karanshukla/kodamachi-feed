<?php

declare(strict_types=1);

namespace Tests\Unit\Backfill;

use Aazsamir\Libphpsky\Model\Meta\ATProtoMetaClient;
use App\Backfill\BackfillService;
use App\Feed\FeedConfig;
use App\Post\PostMatcher;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryFeedPostRepository;
use Tests\Support\RecordingATProtoClient;

/**
 * @internal
 */
final class BackfillServiceTest extends TestCase
{
    #[Test]
    public function indexes_only_what_the_subscription_would_have_matched(): void
    {
        $repository = $this->backfill([
            self::post('text', ['text' => 'Meet me at Kodamachi']),
            self::post('alt', ['text' => 'look', 'embed' => [
                '$type' => 'app.bsky.embed.images',
                'images' => [['alt' => 'kodamachi station', 'image' => self::blob()]],
            ]]),
            // searchPosts matched it on something other than the record text,
            // such as the author's handle or a quoted post.
            self::post('elsewhere', ['text' => 'nothing relevant here']),
        ]);

        self::assertEqualsCanonicalizing(
            ['at://did:plc:author/app.bsky.feed.post/alt', 'at://did:plc:author/app.bsky.feed.post/text'],
            array_map(static fn ($post) => $post->uri, $repository->search(10)),
        );
    }

    #[Test]
    public function reaches_back_as_far_as_retention_does(): void
    {
        $repository = $this->backfill([
            self::post('recent', ['text' => 'kodamachi'], daysAgo: 20),
            self::post('expired', ['text' => 'kodamachi'], daysAgo: 40),
        ]);

        self::assertSame(
            ['at://did:plc:author/app.bsky.feed.post/recent'],
            array_map(static fn ($post) => $post->uri, $repository->search(10)),
        );
    }

    /**
     * @param list<array<string, mixed>> $posts
     */
    private function backfill(array $posts): InMemoryFeedPostRepository
    {
        $config = new FeedConfig(
            hostname: 'feed.test',
            serviceDid: 'did:web:feed.test',
            publisherDid: 'did:plc:publisher',
            textTerms: ['kodamachi'],
            altTerms: ['kodamachi'],
            retentionDays: 30,
            handle: 'publisher.test',
            appPassword: 'app-password',
        );
        $repository = new InMemoryFeedPostRepository();

        new BackfillService(
            $config,
            $repository,
            new PostMatcher($config),
            ATProtoMetaClient::default(new RecordingATProtoClient([['posts' => $posts]])),
            new NullLogger(),
        )->run();

        return $repository;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function post(string $rkey, array $record, int $daysAgo = 1): array
    {
        $at = new DateTimeImmutable("-{$daysAgo} days")->format('Y-m-d\TH:i:s.v\Z');

        return [
            'uri' => "at://did:plc:author/app.bsky.feed.post/{$rkey}",
            'cid' => "cid-{$rkey}",
            'author' => ['did' => 'did:plc:author', 'handle' => 'author.test'],
            'record' => ['$type' => 'app.bsky.feed.post', 'createdAt' => $at, ...$record],
            'indexedAt' => $at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function blob(): array
    {
        return [
            '$type' => 'blob',
            'ref' => ['$link' => 'bafkreigh2akiscaildc'],
            'mimeType' => 'image/jpeg',
            'size' => 1024,
        ];
    }
}
