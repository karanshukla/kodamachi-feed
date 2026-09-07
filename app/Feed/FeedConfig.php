<?php

declare(strict_types=1);

namespace App\Feed;

final readonly class FeedConfig
{
    /**
     * @param list<string> $textTerms matched case-insensitively against post text
     * @param list<string> $altTerms matched case-insensitively against image alt text
     */
    public function __construct(
        public string $hostname,
        public string $serviceDid,
        public string $publisherDid,
        public array $textTerms,
        public array $altTerms,
        public string $recordKey = 'kodamachi',
        public string $displayName = 'Kodamachi',
        public string $description = 'Posts from around Kodamachi.',
        /**
         * Off by default. The skeleton this feed returns is byte-identical for
         * every requester, so requiring service auth buys no privacy and only
         * decides which clients can load the feed at all -- and a client whose
         * token is rejected for any reason shows its users a permanently empty
         * feed, indistinguishable from the feed being broken.
         */
        public bool $requireAuth = false,
        public int $retentionDays = 30,
        public string $subscriptionEndpoint = 'wss://jetstream1.us-east.bsky.network/subscribe',
        public int $reconnectDelaySeconds = 3,
        public ?string $handle = null,
        public ?string $appPassword = null,
        public string $pdsUrl = 'https://bsky.social',
    ) {}

    public function feedUri(): string
    {
        return "at://{$this->publisherDid}/app.bsky.feed.generator/{$this->recordKey}";
    }

    public function canAuthenticate(): bool
    {
        return $this->handle !== null && $this->appPassword !== null;
    }

    /**
     * The queries the backfill sends to searchPosts.
     *
     * @return list<string>
     */
    public function searchTerms(): array
    {
        return array_values(array_unique([...$this->textTerms, ...$this->altTerms]));
    }
}
