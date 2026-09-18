<?php

declare(strict_types=1);

namespace App\Feed;

use App\Api\RateLimiter;
use App\Backfill\BackfillService;
use App\Framework\DatabaseDidDocumentCache;
use App\Post\RetentionService;

/**
 * The periodic work that keeps the index honest, driven by the collector
 * process.
 *
 * It lives here rather than in a cron entry so the container needs one
 * process supervisor and no scheduler: the collector is already a long-lived
 * loop that gets a turn every few seconds.
 */
final class FeedMaintenance
{
    private const int BACKFILL_INTERVAL_SECONDS = 6 * 3600;
    private const int PRUNE_INTERVAL_SECONDS = 24 * 3600;
    private const int RATE_LIMIT_SWEEP_INTERVAL_SECONDS = 3600;

    /**
     * HttpDidDocumentResolver's outer bound: past this a cached document is
     * refetched even when the refetch fails, so keeping it serves nobody.
     */
    private const int DID_DOCUMENT_MAX_AGE_SECONDS = 86400;

    private float $lastBackfillAt = 0.0;
    private float $lastPruneAt = 0.0;
    private float $lastRateLimitSweepAt = 0.0;

    public function __construct(
        private readonly BackfillService $backfill,
        private readonly RetentionService $retention,
        private readonly RateLimiter $rateLimiter,
        private readonly DatabaseDidDocumentCache $didDocuments,
    ) {}

    /**
     * Runs whatever is due.
     *
     * @return float seconds spent, so a caller watching for a stalled socket
     *               can tell "blocked on a search" from "the connection died"
     */
    public function runDue(): float
    {
        $startedAt = microtime(true);
        $now = $startedAt;

        if ($now - $this->lastBackfillAt >= self::BACKFILL_INTERVAL_SECONDS) {
            $this->lastBackfillAt = $now;
            $this->backfill->run();
        }

        if ($now - $this->lastPruneAt >= self::PRUNE_INTERVAL_SECONDS) {
            $this->lastPruneAt = $now;
            $this->retention->prune();
        }

        if ($now - $this->lastRateLimitSweepAt >= self::RATE_LIMIT_SWEEP_INTERVAL_SECONDS) {
            $this->lastRateLimitSweepAt = $now;
            $this->rateLimiter->forget(self::RATE_LIMIT_SWEEP_INTERVAL_SECONDS);
            $this->didDocuments->forget(self::DID_DOCUMENT_MAX_AGE_SECONDS);
        }

        return microtime(true) - $startedAt;
    }
}
