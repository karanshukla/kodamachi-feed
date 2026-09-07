<?php

declare(strict_types=1);

namespace App\Jetstream;

use Aazsamir\Libphpsky\Client\ATProtoClientInterface;
use Aazsamir\Libphpsky\Model\App\Bsky\Feed\Post\Post;
use App\Feed\FeedConfig;
use App\Feed\FeedMaintenance;
use App\Post\FeedPost;
use App\Post\FeedPostRepository;
use App\Post\PostMatcher;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

/**
 * Consumes app.bsky.feed.post commits from Jetstream and indexes the ones
 * that match the feed.
 *
 * Runs as its own process. PHP cannot hold a socket open across web requests,
 * so this is not something the HTTP side can do between serving traffic.
 */
final class JetstreamSubscription
{
    /**
     * Jetstream filtered to app.bsky.feed.post still carries every post on the
     * network, hundreds a second. A full minute of silence therefore means the
     * socket is dead, not that the network is quiet -- and a half-open TCP
     * connection (an idle proxy or NAT dropping state) never reports itself as
     * closed, so without this check the subscription sits there receiving
     * nothing, forever, saying nothing.
     */
    private const int STALL_TIMEOUT_SECONDS = 60;

    /** How long a single blocking read waits before the loop gets a turn. */
    private const int RECEIVE_TIMEOUT_SECONDS = 15;

    private const int CURSOR_SAVE_INTERVAL_SECONDS = 30;
    private const int HEALTH_REPORT_INTERVAL_SECONDS = 300;

    private bool $running = false;
    private ?int $cursor = null;
    private ?int $savedCursor = null;
    private float $lastMessageAt = 0.0;
    private float $lastCursorSaveAt = 0.0;
    private float $lastHealthReportAt = 0.0;
    private bool $connected = false;
    private int $eventsSinceReport = 0;
    private int $matchesSinceReport = 0;

    public function __construct(
        private readonly FeedConfig $config,
        private readonly FeedPostRepository $repository,
        private readonly PostMatcher $matcher,
        private readonly FeedMaintenance $maintenance,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        $this->running = true;
        $this->cursor = $this->repository->cursor($this->config->subscriptionEndpoint);
        $this->savedCursor = $this->cursor;
        $this->lastCursorSaveAt = microtime(true);
        $this->lastHealthReportAt = microtime(true);

        while ($this->running) {
            try {
                $client = $this->connect();
            } catch (Throwable $e) {
                $this->connected = false;
                $this->logger->error('jetstream could not connect: {error}', ['error' => $e->getMessage()]);
                $this->sleep($this->config->reconnectDelaySeconds);

                continue;
            }

            $this->consume($client);

            try {
                $client->disconnect();
            } catch (Throwable) {
                // Already gone; nothing to tidy up.
            }

            if ($this->running) {
                $this->sleep($this->config->reconnectDelaySeconds);
            }
        }

        $this->persistCursor();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function connect(): Client
    {
        $query = ['wantedCollections' => Post::ID];

        if ($this->cursor !== null) {
            $query['cursor'] = (string) $this->cursor;
        }

        $url = $this->config->subscriptionEndpoint . '?' . http_build_query($query);

        $client = new Client($url);
        $client
            ->addMiddleware(new CloseHandler())
            // Jetstream pings idle connections; without a pong it hangs up.
            ->addMiddleware(new PingResponder())
            ->addHeader('User-Agent', ATProtoClientInterface::USER_AGENT)
            ->setTimeout(self::RECEIVE_TIMEOUT_SECONDS);

        $client->connect();

        $this->connected = true;
        // Treat the connection as live from the moment it opens, so the
        // watchdog does not tear down a socket that has not yet said anything.
        $this->lastMessageAt = microtime(true);

        $this->logger->info('jetstream connected to {endpoint} at {position}', [
            'endpoint' => $this->config->subscriptionEndpoint,
            'position' => $this->cursor !== null ? "cursor {$this->cursor}" : 'live tip',
        ]);

        return $client;
    }

    private function consume(Client $client): void
    {
        while ($this->running) {
            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                // Not an error in itself -- it is how the loop gets a turn to
                // save the cursor, report health, and check for a stall.
                if ($this->tick()) {
                    return;
                }

                continue;
            } catch (Throwable $e) {
                $this->connected = false;
                $this->logger->warning('jetstream disconnected: {error}', ['error' => $e->getMessage()]);

                return;
            }

            $this->lastMessageAt = microtime(true);
            $this->eventsSinceReport++;

            if ($message instanceof Text) {
                $this->handle($message->getContent());
            }

            if ($this->tick()) {
                return;
            }
        }
    }

    /**
     * Periodic housekeeping, run between messages.
     *
     * @return bool whether the connection should be dropped and reopened
     */
    private function tick(): bool
    {
        // Backfill searches block this loop for as long as they take.
        // Charging that time to the socket would look exactly like silence, so
        // the stall clock is moved forward by however long it cost.
        $this->lastMessageAt += $this->maintenance->runDue();

        $now = microtime(true);

        if ($now - $this->lastCursorSaveAt >= self::CURSOR_SAVE_INTERVAL_SECONDS) {
            $this->persistCursor();
            $this->lastCursorSaveAt = $now;
        }

        if ($now - $this->lastHealthReportAt >= self::HEALTH_REPORT_INTERVAL_SECONDS) {
            // Reported unconditionally, including when both counts are zero.
            // "No events" and "no log line" are different states, and telling
            // them apart is the entire point: a quiet feed and a dead
            // subscription look identical without this.
            $this->logger->info('jetstream: {events} events, {matches} matched in the last 5m (connected={connected})', [
                'events' => $this->eventsSinceReport,
                'matches' => $this->matchesSinceReport,
                'connected' => $this->connected ? 'true' : 'false',
            ]);

            $this->eventsSinceReport = 0;
            $this->matchesSinceReport = 0;
            $this->lastHealthReportAt = $now;
        }

        $silentFor = $now - $this->lastMessageAt;

        if ($silentFor >= self::STALL_TIMEOUT_SECONDS) {
            $this->connected = false;
            $this->logger->error('jetstream silent for {seconds}s, reconnecting', [
                'seconds' => (int) round($silentFor),
            ]);

            return true;
        }

        return false;
    }

    private function handle(string $payload): void
    {
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (!is_array($event) || ($event['kind'] ?? null) !== 'commit') {
            return;
        }

        $commit = $event['commit'] ?? null;
        $did = $event['did'] ?? null;

        if (!is_array($commit) || !is_string($did) || ($commit['collection'] ?? null) !== Post::ID) {
            return;
        }

        // Advance the cursor for every commit seen, matched or not. It is the
        // stream position, not a record of what was indexed.
        if (is_int($event['time_us'] ?? null)) {
            $this->cursor = $event['time_us'];
        }

        $rkey = $commit['rkey'] ?? null;

        if (!is_string($rkey)) {
            return;
        }

        $uri = "at://{$did}/" . Post::ID . "/{$rkey}";

        if (($commit['operation'] ?? null) === 'delete') {
            $this->repository->delete($uri);

            return;
        }

        $record = $commit['record'] ?? null;
        $cid = $commit['cid'] ?? null;

        if (($commit['operation'] ?? null) !== 'create' || !is_array($record) || !is_string($cid)) {
            return;
        }

        $now = (int) round(microtime(true) * 1000);

        // A post older than the retention window would be pruned on the next
        // sweep anyway, so indexing it just to delete it is wasted work. This
        // matters on a resumed cursor, which replays the backlog.
        if (!$this->withinRetention($record, $now)) {
            return;
        }

        $reason = $this->matcher->match($record);

        if ($reason === null) {
            return;
        }

        $saved = $this->repository->save(new FeedPost(uri: $uri, cid: $cid, indexedAt: $now));

        if (!$saved) {
            return;
        }

        $this->matchesSinceReport++;
        $this->logger->info('indexed [{reason}] {uri}', ['reason' => $reason, 'uri' => $uri]);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function withinRetention(array $record, int $nowMs): bool
    {
        $createdAt = $record['createdAt'] ?? null;

        if (!is_string($createdAt)) {
            return true;
        }

        $parsed = strtotime($createdAt);

        if ($parsed === false) {
            return true;
        }

        return $parsed * 1000 >= $nowMs - ($this->config->retentionDays * 86400 * 1000);
    }

    private function persistCursor(): void
    {
        if ($this->cursor === null || $this->cursor === $this->savedCursor) {
            return;
        }

        $this->repository->saveCursor($this->config->subscriptionEndpoint, $this->cursor);
        $this->savedCursor = $this->cursor;
    }

    /**
     * Sleeps in short slices so a signal is noticed promptly.
     */
    private function sleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && $this->running; $i++) {
            sleep(1);
        }
    }
}
