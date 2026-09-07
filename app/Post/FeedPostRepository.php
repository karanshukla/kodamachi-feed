<?php

declare(strict_types=1);

namespace App\Post;

interface FeedPostRepository
{
    /**
     * @return bool whether the post was new
     */
    public function save(FeedPost $post): bool;

    public function delete(string $uri): void;

    /**
     * @param int|null $before a cursor in milliseconds; only older posts are returned
     *
     * @return list<FeedPost> newest first
     */
    public function search(int $limit, ?int $before = null): array;

    public function count(): int;

    public function newestIndexedAt(): ?int;

    /**
     * @return int the number of posts removed
     */
    public function pruneOlderThan(int $indexedAt): int;

    public function cursor(string $service): ?int;

    public function saveCursor(string $service, int $cursor): void;
}
