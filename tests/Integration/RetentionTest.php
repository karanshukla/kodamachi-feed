<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Post\FeedPost;
use App\Post\FeedPostRepository;
use App\Post\RetentionService;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
final class RetentionTest extends IntegrationTestCase
{
    #[Test]
    public function prunes_posts_past_retention_and_reclaims_the_space(): void
    {
        $repository = $this->container->get(FeedPostRepository::class);
        $now = (int) (microtime(true) * 1000);
        $repository->save(new FeedPost('at://did:plc:a/app.bsky.feed.post/old', 'cid1', $now - 40 * 86400 * 1000));
        $repository->save(new FeedPost('at://did:plc:a/app.bsky.feed.post/new', 'cid2', $now));

        // Runs inside the collector's loop, so a VACUUM that throws would take
        // the collector down with it on every restart.
        $removed = $this->container->get(RetentionService::class)->prune();

        self::assertSame(1, $removed);
        self::assertSame(['at://did:plc:a/app.bsky.feed.post/new'], array_map(
            static fn (FeedPost $post) => $post->uri,
            $repository->search(10),
        ));
    }
}
