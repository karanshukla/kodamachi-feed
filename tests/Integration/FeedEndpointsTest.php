<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Post\FeedPost;
use App\Post\FeedPostRepository;
use PHPUnit\Framework\Attributes\Test;
use Tempest\Framework\Testing\Http\TestResponseHelper;
use Tempest\Http\Status;

/**
 * @internal
 */
final class FeedEndpointsTest extends IntegrationTestCase
{
    private const string FEED = 'at://did:plc:publisher/app.bsky.feed.generator/kodamachi';

    #[Test]
    public function serves_the_did_document_the_appview_resolves(): void
    {
        $response = $this->http
            ->get('/.well-known/did.json')
            ->assertOk();

        self::assertSame(
            [
                '@context' => ['https://www.w3.org/ns/did/v1'],
                'id' => 'did:web:feed.test',
                'service' => [
                    [
                        'id' => '#bsky_fg',
                        'type' => 'BskyFeedGenerator',
                        'serviceEndpoint' => 'https://feed.test',
                    ],
                ],
            ],
            self::json($response),
        );
    }

    #[Test]
    public function describes_the_feed_it_serves(): void
    {
        $response = $this->http
            ->get('/xrpc/app.bsky.feed.describeFeedGenerator')
            ->assertOk();

        $body = self::json($response);

        self::assertSame('did:web:feed.test', $body['did']);
        self::assertSame(self::FEED, $body['feeds'][0]['uri']);
    }

    #[Test]
    public function keeps_the_skeleton_out_of_shared_caches(): void
    {
        $this->http
            ->get('/xrpc/app.bsky.feed.getFeedSkeleton?feed=' . urlencode(self::FEED))
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'private, max-age=60, stale-while-revalidate=30');

        $this->http
            ->get('/xrpc/app.bsky.feed.describeFeedGenerator')
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'public, max-age=3600, stale-while-revalidate=600');
    }

    #[Test]
    public function serves_an_empty_skeleton_before_anything_is_indexed(): void
    {
        $response = $this->http
            ->get('/xrpc/app.bsky.feed.getFeedSkeleton?feed=' . urlencode(self::FEED))
            ->assertOk();

        self::assertSame([], self::json($response)['feed']);
    }

    #[Test]
    public function serves_indexed_posts_newest_first(): void
    {
        $repository = $this->container->get(FeedPostRepository::class);
        $repository->save(new FeedPost('at://did:plc:a/app.bsky.feed.post/older', 'cid1', 1000));
        $repository->save(new FeedPost('at://did:plc:a/app.bsky.feed.post/newer', 'cid2', 2000));

        $response = $this->http
            ->get('/xrpc/app.bsky.feed.getFeedSkeleton?feed=' . urlencode(self::FEED))
            ->assertOk();

        $body = self::json($response);

        self::assertSame(
            ['at://did:plc:a/app.bsky.feed.post/newer', 'at://did:plc:a/app.bsky.feed.post/older'],
            array_column($body['feed'], 'post'),
        );
        self::assertSame('1000', $body['cursor']);
    }

    /**
     * The error name matters as much as the status: clients switch on it.
     */
    #[Test]
    public function rejects_a_feed_this_service_does_not_serve(): void
    {
        $response = $this->http
            ->get('/xrpc/app.bsky.feed.getFeedSkeleton?feed=' . urlencode('at://did:plc:someone/app.bsky.feed.generator/other'))
            ->assertStatus(Status::BAD_REQUEST);

        self::assertSame(
            'UnsupportedAlgorithm',
            self::json($response)['error'],
        );
    }

    #[Test]
    public function rejects_a_request_with_no_feed_parameter(): void
    {
        $this->http
            ->get('/xrpc/app.bsky.feed.getFeedSkeleton')
            ->assertStatus(Status::BAD_REQUEST);
    }

    /**
     * Auth is optional, and the skeleton is identical for every requester, so
     * a token this service cannot verify must not cost the caller the feed.
     */
    #[Test]
    public function serves_the_feed_anonymously_when_a_token_cannot_be_verified(): void
    {
        $this->http
            ->get(
                uri: '/xrpc/app.bsky.feed.getFeedSkeleton?feed=' . urlencode(self::FEED),
                headers: ['Authorization' => 'Bearer not.a.jwt'],
            )
            ->assertOk();
    }

    #[Test]
    public function sets_security_headers_on_every_response(): void
    {
        $this->http
            ->get('/.well-known/did.json')
            ->assertHasHeader('X-Content-Type-Options')
            ->assertHasHeader('Content-Security-Policy');
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(TestResponseHelper $response): array
    {
        return json_decode((string) json_encode($response->body), true);
    }
}
