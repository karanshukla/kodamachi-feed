<?php

declare(strict_types=1);

namespace App\Backfill;

use Aazsamir\Libphpsky\Model\App\Bsky\Feed\Defs\PostView;
use Aazsamir\Libphpsky\Model\Meta\ATProtoMetaClient;
use App\Feed\FeedConfig;
use App\Post\FeedPost;
use App\Post\FeedPostRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recovers posts the subscription missed.
 *
 * Jetstream only replays as far back as its own retention, so a long outage
 * (or a wiped database) leaves a hole the cursor cannot close. searchPosts
 * can, at the cost of needing credentials, so this is skipped rather than
 * fatal when none are configured.
 */
final readonly class BackfillService
{
    private const int SEARCH_LIMIT = 100;
    private const int WINDOW_DAYS = 14;

    public function __construct(
        private FeedConfig $config,
        private FeedPostRepository $repository,
        private ATProtoMetaClient $metaClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return int the number of posts newly indexed
     */
    public function run(): int
    {
        if (!$this->config->canAuthenticate()) {
            $this->logger->info('backfill skipped: no FEEDGEN_HANDLE / FEEDGEN_APP_PASSWORD configured');

            return 0;
        }

        $since = (new DateTimeImmutable())->modify('-' . self::WINDOW_DAYS . ' days');
        $indexed = 0;

        foreach ($this->config->searchTerms() as $term) {
            try {
                $indexed += $this->backfillTerm($term, $since);
            } catch (Throwable $e) {
                // One failing term should not cost the others their results.
                $this->logger->error('backfill failed for "{term}": {error}', [
                    'term' => $term,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('backfill indexed {count} new posts', ['count' => $indexed]);

        return $indexed;
    }

    private function backfillTerm(string $term, DateTimeImmutable $since): int
    {
        $search = $this->metaClient->appBskyFeedSearchPosts();

        if ($this->config->pdsUrl !== '') {
            $search = $search->withEndpoint($this->config->pdsUrl);
        }

        $output = $search->query(q: $term, limit: self::SEARCH_LIMIT);
        $indexed = 0;

        foreach ($output->posts as $post) {
            if (!$post instanceof PostView || $post->indexedAt < $since) {
                continue;
            }

            $saved = $this->repository->save(new FeedPost(
                uri: $post->uri,
                cid: $post->cid,
                indexedAt: (int) $post->indexedAt->format('Uv'),
            ));

            if ($saved) {
                $indexed++;
                $this->logger->info('backfilled {uri}', ['uri' => $post->uri]);
            }
        }

        return $indexed;
    }
}
