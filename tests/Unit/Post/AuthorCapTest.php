<?php

declare(strict_types=1);

namespace Tests\Unit\Post;

use App\Post\AuthorCap;
use App\Post\FeedPost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryFeedPostRepository;

/**
 * @internal
 */
final class AuthorCapTest extends TestCase
{
    private const int NOW = 1_800_000_000_000;

    #[Test]
    public function refuses_an_author_already_at_the_cap(): void
    {
        $repository = self::repositoryWith('did:plc:busy', AuthorCap::MAX_POSTS, indexedAt: self::NOW);

        self::assertFalse(new AuthorCap($repository)->allows(self::post('did:plc:busy', 'next')));
    }

    #[Test]
    public function allows_an_author_below_the_cap(): void
    {
        $repository = self::repositoryWith('did:plc:busy', AuthorCap::MAX_POSTS - 1, indexedAt: self::NOW);

        self::assertTrue(new AuthorCap($repository)->allows(self::post('did:plc:busy', 'next')));
    }

    #[Test]
    public function counts_only_that_authors_posts(): void
    {
        $repository = self::repositoryWith('did:plc:busy', AuthorCap::MAX_POSTS, indexedAt: self::NOW);

        self::assertTrue(new AuthorCap($repository)->allows(self::post('did:plc:quiet', 'first')));
    }

    #[Test]
    public function stops_counting_posts_once_they_leave_the_window(): void
    {
        $repository = self::repositoryWith('did:plc:busy', AuthorCap::MAX_POSTS, indexedAt: self::NOW - AuthorCap::WINDOW_MS - 1);

        self::assertTrue(new AuthorCap($repository)->allows(self::post('did:plc:busy', 'next')));
    }

    private static function repositoryWith(string $did, int $count, int $indexedAt): InMemoryFeedPostRepository
    {
        $repository = new InMemoryFeedPostRepository();

        for ($i = 0; $i < $count; $i++) {
            $repository->save(new FeedPost("at://{$did}/app.bsky.feed.post/{$i}", "cid{$i}", $indexedAt));
        }

        return $repository;
    }

    private static function post(string $did, string $rkey): FeedPost
    {
        return new FeedPost("at://{$did}/app.bsky.feed.post/{$rkey}", 'cid', self::NOW);
    }
}
