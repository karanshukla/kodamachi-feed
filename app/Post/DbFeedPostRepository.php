<?php

declare(strict_types=1);

namespace App\Post;

use Tempest\Container\Autowire;
use Tempest\Database\Direction;

use function Tempest\Database\query;

#[Autowire]
final class DbFeedPostRepository implements FeedPostRepository
{
    public function save(FeedPost $post): bool
    {
        if ($this->exists($post->uri)) {
            return false;
        }

        query('posts')
            ->insert([
                'uri' => $post->uri,
                'cid' => $post->cid,
                'indexed_at' => $post->indexedAt,
            ])
            ->execute();

        return true;
    }

    public function delete(string $uri): void
    {
        query('posts')
            ->delete()
            ->where('uri = ?', $uri)
            ->execute();
    }

    public function search(int $limit, ?int $before = null): array
    {
        $query = query('posts')
            ->select('uri', 'cid', 'indexed_at')
            ->orderBy('indexed_at', Direction::DESC)
            // Two posts can share a millisecond, and a cursor that only knows
            // the timestamp would then either repeat or skip one. Ordering by
            // uri as well at least makes the page boundary deterministic.
            ->orderBy('uri', Direction::DESC)
            ->limit($limit);

        if ($before !== null) {
            $query = $query->where('indexed_at < ?', $before);
        }

        return array_map(
            static fn (array $row): FeedPost => new FeedPost(
                uri: (string) $row['uri'],
                cid: (string) $row['cid'],
                indexedAt: (int) $row['indexed_at'],
            ),
            $query->all(),
        );
    }

    public function count(): int
    {
        return query('posts')->count()->execute();
    }

    public function newestIndexedAt(): ?int
    {
        $row = query('posts')
            ->select('indexed_at')
            ->orderBy('indexed_at', Direction::DESC)
            ->first();

        return $row === null ? null : (int) $row['indexed_at'];
    }

    public function pruneOlderThan(int $indexedAt): int
    {
        $doomed = query('posts')
            ->select('uri')
            ->where('indexed_at < ?', $indexedAt)
            ->all();

        if ($doomed === []) {
            return 0;
        }

        query('posts')
            ->delete()
            ->where('indexed_at < ?', $indexedAt)
            ->execute();

        return count($doomed);
    }

    public function cursor(string $service): ?int
    {
        $row = query('sub_state')
            ->select('cursor')
            ->where('service = ?', $service)
            ->first();

        return $row === null ? null : (int) $row['cursor'];
    }

    public function saveCursor(string $service, int $cursor): void
    {
        $exists = query('sub_state')
            ->select('service')
            ->where('service = ?', $service)
            ->first() !== null;

        if ($exists) {
            query('sub_state')
                ->update(cursor: $cursor)
                ->where('service = ?', $service)
                ->execute();

            return;
        }

        query('sub_state')
            ->insert(['service' => $service, 'cursor' => $cursor])
            ->execute();
    }

    private function exists(string $uri): bool
    {
        return query('posts')
            ->select('uri')
            ->where('uri = ?', $uri)
            ->first() !== null;
    }
}
