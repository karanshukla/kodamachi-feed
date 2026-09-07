<?php

declare(strict_types=1);

use App\Feed\FeedConfig;

use function Tempest\env;

/**
 * @return list<string>
 */
$terms = static function (string $key, string $default): array {
    $raw = (string) env($key, $default);

    return array_values(array_filter(array_map(
        static fn (string $term): string => mb_strtolower(trim($term)),
        explode(',', $raw),
    )));
};

$hostname = (string) env('FEEDGEN_HOSTNAME', 'localhost');
$serviceDid = env('FEEDGEN_SERVICE_DID');

return new FeedConfig(
    hostname: $hostname,
    // did:web is derived from the hostname rather than generated, so it
    // survives a redeploy. A token's `aud` must equal this exactly; a service
    // DID that changes on every boot fails every request after each deploy.
    serviceDid: is_string($serviceDid) && $serviceDid !== '' ? $serviceDid : 'did:web:' . $hostname,
    publisherDid: (string) env('FEEDGEN_PUBLISHER_DID', 'did:example:alice'),
    textTerms: $terms('FEEDGEN_MATCH_TEXT', 'kodamachi'),
    altTerms: $terms('FEEDGEN_MATCH_ALT', 'kodamachi'),
    recordKey: (string) env('FEEDGEN_RECORD_KEY', 'kodamachi'),
    displayName: (string) env('FEEDGEN_DISPLAY_NAME', 'Kodamachi'),
    description: (string) env('FEEDGEN_DESCRIPTION', 'Posts from around Kodamachi.'),
    requireAuth: (bool) env('FEEDGEN_REQUIRE_AUTH', false),
    retentionDays: (int) env('FEEDGEN_RETENTION_DAYS', 30),
    subscriptionEndpoint: (string) env(
        'FEEDGEN_SUBSCRIPTION_ENDPOINT',
        'wss://jetstream1.us-east.bsky.network/subscribe',
    ),
    reconnectDelaySeconds: (int) env('FEEDGEN_RECONNECT_DELAY_SECONDS', 3),
    handle: env('FEEDGEN_HANDLE') ?: null,
    appPassword: env('FEEDGEN_APP_PASSWORD') ?: null,
    pdsUrl: (string) env('FEEDGEN_PDS_URL', 'https://bsky.social'),
);
