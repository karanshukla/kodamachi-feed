<?php

declare(strict_types=1);

namespace App\Backfill;

use Aazsamir\Libphpsky\Client\AuthException;
use Aazsamir\Libphpsky\Model\Meta\ATProtoMetaClient;
use App\Feed\FeedConfig;
use App\Post\FeedPost;
use App\Post\FeedPostRepository;
use App\Post\PostMatcher;
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
 *
 * Search results go through the same PostMatcher as the live subscription.
 * searchPosts matches on more than the record text (handles, link cards,
 * quoted posts), and without the check the backfill would index posts the
 * subscription would have turned away.
 */
final readonly class BackfillService
{
    private const int SEARCH_LIMIT = 100;

    public function __construct(
        private FeedConfig $config,
        private FeedPostRepository $repository,
        private PostMatcher $matcher,
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

        // The same window the subscription accepts, so a post the live path
        // would have kept is not one the backfill considers too old.
        $since = (new DateTimeImmutable())->modify('-' . $this->config->retentionDays . ' days');
        $indexed = 0;

        foreach ($this->config->searchTerms() as $term) {
            try {
                $indexed += $this->backfillTerm($term, $since);
            } catch (AuthException $e) {
                // Every other term would fail the same way, and the fix is the
                // same for all of them.
                $this->logger->warning('backfill skipped: {error}. FEEDGEN_HANDLE or FEEDGEN_APP_PASSWORD is invalid; generate a new app password at bsky.app, Settings, App Passwords', [
                    'error' => $e->getMessage(),
                ]);

                return $indexed;
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

        // Raw rather than hydrated: libphpsky drops a record's embed when it
        // builds a PostView, and the embed is where the alt text lives.
        $output = $search->rawQuery(q: $term, limit: self::SEARCH_LIMIT);
        $posts = $output['posts'] ?? [];
        $indexed = 0;

        if (!is_array($posts)) {
            return 0;
        }

        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $uri = $post['uri'] ?? null;
            $cid = $post['cid'] ?? null;
            $record = $post['record'] ?? null;
            $indexedAt = self::parseTime($post['indexedAt'] ?? null);

            if (!is_string($uri) || !is_string($cid) || !is_array($record) || $indexedAt === null) {
                continue;
            }

            if ($indexedAt < $since || $this->matcher->match($record) === null) {
                continue;
            }

            $saved = $this->repository->save(new FeedPost(
                uri: $uri,
                cid: $cid,
                indexedAt: (int) $indexedAt->format('Uv'),
            ));

            if ($saved) {
                $indexed++;
                $this->logger->info('backfilled {uri}', ['uri' => $uri]);
            }
        }

        return $indexed;
    }

    private static function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
