<?php

declare(strict_types=1);

namespace App\Post;

use App\Feed\FeedConfig;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Tempest\Database\Query;

final readonly class RetentionService
{
    public function __construct(
        private FeedConfig $config,
        private FeedPostRepository $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return int the number of posts removed
     */
    public function prune(): int
    {
        $cutoff = (int) (new DateTimeImmutable())
            ->modify('-' . $this->config->retentionDays . ' days')
            ->format('Uv');

        $removed = $this->repository->pruneOlderThan($cutoff);

        if ($removed > 0) {
            $this->logger->info('pruned {count} posts older than {days} days', [
                'count' => $removed,
                'days' => $this->config->retentionDays,
            ]);

            // SQLite keeps freed pages in the file rather than returning them,
            // and the file lives on a metered volume.
            new Query('VACUUM')->execute();
        }

        return $removed;
    }
}
