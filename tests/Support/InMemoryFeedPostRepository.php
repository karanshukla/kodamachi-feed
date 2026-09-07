<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Post\FeedPost;
use App\Post\FeedPostRepository;

final class InMemoryFeedPostRepository implements FeedPostRepository
{
    /** @var array<string, FeedPost> */
    private array $posts = [];

    /** @var array<string, int> */
    private array $cursors = [];

    public function save(FeedPost $post): bool
    {
        if (isset($this->posts[$post->uri])) {
            return false;
        }

        $this->posts[$post->uri] = $post;

        return true;
    }

    public function delete(string $uri): void
    {
        unset($this->posts[$uri]);
    }

    public function search(int $limit, ?int $before = null): array
    {
        $posts = array_values(array_filter(
            $this->posts,
            static fn (FeedPost $post): bool => $before === null || $post->indexedAt < $before,
        ));

        usort(
            $posts,
            static fn (FeedPost $a, FeedPost $b): int => [$b->indexedAt, $b->uri] <=> [$a->indexedAt, $a->uri],
        );

        return array_slice($posts, 0, $limit);
    }

    public function count(): int
    {
        return count($this->posts);
    }

    public function newestIndexedAt(): ?int
    {
        $newest = $this->search(1);

        return $newest === [] ? null : $newest[0]->indexedAt;
    }

    public function pruneOlderThan(int $indexedAt): int
    {
        $before = count($this->posts);

        $this->posts = array_filter(
            $this->posts,
            static fn (FeedPost $post): bool => $post->indexedAt >= $indexedAt,
        );

        return $before - count($this->posts);
    }

    public function cursor(string $service): ?int
    {
        return $this->cursors[$service] ?? null;
    }

    public function saveCursor(string $service, int $cursor): void
    {
        $this->cursors[$service] = $cursor;
    }
}
