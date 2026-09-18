<?php

declare(strict_types=1);

namespace App\Api;

use Aazsamir\Libphpsky\Model\App\Bsky\Feed\Generator\Generator;
use Aazsamir\Libphpsky\Type\ATUri;
use App\Feed\FeedConfig;
use App\Feed\FeedService;
use Tempest\Http\Response;
use Tempest\Http\Responses\Json;
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

        try {
            $requesterDid = $this->authenticator->authenticate($request, self::NSID);
        } catch (AuthenticationFailed $e) {
            return XrpcError::authRequired($e->getMessage());
        }

        // Anonymous callers share a bucket keyed by address, and getFeedSkeleton
        // is called by the AppView server-side, so that bucket covers every
        // viewer behind a given AppView rather than one person.
        $bucket = $requesterDid !== null
            ? 'skeleton:did:' . $requesterDid
            : 'skeleton:ip:' . ClientAddress::of($request);

        if (!$this->rateLimiter->hit($bucket, self::REQUESTS_PER_MINUTE, 60)) {
            return XrpcError::rateLimited('Rate limit exceeded. Please try again later.');
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
