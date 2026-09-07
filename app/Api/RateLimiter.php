<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Container\Autowire;

use function Tempest\Database\query;

/**
 * Fixed-window request counters.
 *
 * Backed by the database rather than process memory because a request may be
 * served by any worker: an in-process counter would give each worker its own
 * allowance and enforce nothing in aggregate.
 */
#[Autowire]
final class RateLimiter
{
    /**
     * @return bool whether the request is within its allowance
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $now = time();
        $row = query('rate_limits')
            ->select('hits', 'window_start')
            ->where('bucket = ?', $bucket)
            ->first();

        if ($row === null) {
            query('rate_limits')
                ->insert(['bucket' => $bucket, 'hits' => 1, 'window_start' => $now])
                ->execute();

            return true;
        }

        if ($now - (int) $row['window_start'] >= $windowSeconds) {
            query('rate_limits')
                ->update(hits: 1, window_start: $now)
                ->where('bucket = ?', $bucket)
                ->execute();

            return true;
        }

        $hits = (int) $row['hits'];

        if ($hits >= $limit) {
            return false;
        }

        query('rate_limits')
            ->update(hits: $hits + 1)
            ->where('bucket = ?', $bucket)
            ->execute();

        return true;
    }

    /**
     * Drops windows that closed long ago, so the table does not grow without
     * bound. Called from the collector's housekeeping, not from a request.
     */
    public function forget(int $olderThanSeconds): void
    {
        query('rate_limits')
            ->delete()
            ->where('window_start < ?', time() - $olderThanSeconds)
            ->execute();
    }
}
