<?php

declare(strict_types=1);

namespace App\Post;

/**
 * Bounds how many posts one account can have in the feed at a time.
 *
 * Matching is by keyword alone, so without this a single account repeating
 * the term could fill the feed. The limit is well above what anyone posting
 * about Kodamachi does in a day.
 */
final readonly class AuthorCap
{
    public const int MAX_POSTS = 10;

    public const int WINDOW_MS = 86_400_000;

    public function __construct(
        private FeedPostRepository $repository,
    ) {}

    public function allows(FeedPost $post): bool
    {
        return $this->repository->countByAuthorSince($post->authorDid(), $post->indexedAt - self::WINDOW_MS) < self::MAX_POSTS;
    }
}
