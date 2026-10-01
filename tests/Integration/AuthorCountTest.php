<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Post\FeedPost;
use App\Post\FeedPostRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
final class AuthorCountTest extends IntegrationTestCase
{
    #[Test]
    public function counts_one_authors_posts_since_a_time(): void
    {
        $repository = $this->container->get(FeedPostRepository::class);
        $repository->save(new FeedPost('at://did:plc:a/app.bsky.feed.post/old', 'cid1', 1000));
        $repository->save(new FeedPost('at://did:plc:a/app.bsky.feed.post/new', 'cid2', 3000));
        $repository->save(new FeedPost('at://did:plc:ab/app.bsky.feed.post/other', 'cid3', 3000));

        self::assertSame(1, $repository->countByAuthorSince('did:plc:a', 2000));
        self::assertSame(2, $repository->countByAuthorSince('did:plc:a', 0));
    }
}
