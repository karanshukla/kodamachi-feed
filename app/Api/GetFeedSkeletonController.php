<?php

declare(strict_types=1);

namespace App\Api;

use Aazsamir\Libphpsky\Model\App\Bsky\Feed\Generator\Generator;
use Aazsamir\Libphpsky\Type\ATUri;
use App\Feed\FeedConfig;
use App\Feed\FeedService;
use Tempest\Http\Response;
use Tempest\Router\Get;
use Throwable;

final readonly class GetFeedSkeletonController
{
    private const string NSID = 'app.bsky.feed.getFeedSkeleton';

    /**
     * The Bluesky client refetches the skeleton on every feed open, refresh,
     * tab switch and prefetch, per device, so this sits well above normal
     * client behaviour: it is a shield against abuse, not a quota. A tripped
     * limit shows the user whatever they already had, which reads as "the feed
     * stopped updating" rather than as an error, so a limit that is too tight
     * is worse than none.
     */
    private const int REQUESTS_PER_MINUTE = 100;

    public function __construct(
        private FeedConfig $config,
        private FeedService $feedService,
        private RequestAuthenticator $authenticator,
        private RateLimiter $rateLimiter,
    ) {}

    #[Get('/xrpc/app.bsky.feed.getFeedSkeleton')]
    public function __invoke(GetFeedSkeletonRequest $request): Response
    {
        if (!$this->servesFeed($request->feed)) {
            return XrpcError::invalidRequest('UnsupportedAlgorithm', 'Unsupported algorithm');
        }

        $clientIp = ClientAddress::of($request);
        $ipBucket = 'skeleton:ip:' . $clientIp;

        // Anonymous callers share a bucket keyed by address, and getFeedSkeleton
        // is called by the AppView server-side, so that bucket covers every
        // viewer behind a given AppView rather than one person. Check the per-IP
        // limit before authenticating when no Bearer token is presented so
        // unauthenticated requests are throttled before any auth path runs, and
        // gate auth attempts behind a per-IP auth budget before outbound DID
        // resolution so a flood of forged tokens cannot trigger unbounded
        // directory resolutions.
        $hasBearer = str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ');

        if (!$hasBearer) {
            if (!$this->rateLimiter->hit($ipBucket, self::REQUESTS_PER_MINUTE, 60)) {
                return XrpcError::rateLimited('Rate limit exceeded. Please try again later.');
            }
        } elseif (!$this->rateLimiter->hit('skeleton:auth-attempt:ip:' . $clientIp, self::REQUESTS_PER_MINUTE * 3, 60)) {
            return XrpcError::rateLimited('Rate limit exceeded. Please try again later.');
        }

        try {
            $requesterDid = $this->authenticator->authenticate($request, self::NSID);
        } catch (AuthenticationFailed $e) {
            if (!$this->rateLimiter->hit($ipBucket, self::REQUESTS_PER_MINUTE, 60)) {
                return XrpcError::rateLimited('Rate limit exceeded. Please try again later.');
            }

            return XrpcError::authRequired($e->getMessage());
        }

        if ($requesterDid !== null) {
            $bareDid = explode('#', $requesterDid, 2)[0];

            if (!$this->rateLimiter->hit('skeleton:did:' . $bareDid, self::REQUESTS_PER_MINUTE, 60)) {
                return XrpcError::rateLimited('Rate limit exceeded. Please try again later.');
            }
        } elseif ($hasBearer) {
            if (!$this->rateLimiter->hit($ipBucket, self::REQUESTS_PER_MINUTE, 60)) {
                return XrpcError::rateLimited('Rate limit exceeded. Please try again later.');
            }
        }

        return new Json(
            $this->feedService->getFeed($request->limit, $request->cursor)->toArray(),
            // `private` so no shared cache (Railway's edge, the AppView's
            // fetcher) holds a skeleton served behind an Authorization header
            // and hands it to someone else. With one, the feed looked fresh for
            // some accounts and stale for others depending on the node they hit.
            headers: ['Cache-Control' => 'private, max-age=60, stale-while-revalidate=30'],
        );
    }

    private function servesFeed(string $feed): bool
    {
        if ($feed === '') {
            return false;
        }

        try {
            $uri = ATUri::fromString($feed);
        } catch (Throwable) {
            return false;
        }

        return $uri->getDid() === $this->config->publisherDid
            && $uri->getCollection() === Generator::ID
            && $uri->getRecordKey() === $this->config->recordKey;
    }
}
