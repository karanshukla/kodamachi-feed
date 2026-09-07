<?php

declare(strict_types=1);

namespace App\Api;

use App\Feed\FeedConfig;
use App\ServiceAuth\ServiceAuthException;
use App\ServiceAuth\ServiceAuthVerifier;
use Psr\Log\LoggerInterface;
use Tempest\Http\Request;

final readonly class RequestAuthenticator
{
    public function __construct(
        private FeedConfig $config,
        private ServiceAuthVerifier $verifier,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string $lxm the NSID being called
     *
     * @return string|null the requester's DID, or null for an anonymous request
     *
     * @throws AuthenticationFailed when auth is required and the token is not good
     */
    public function authenticate(Request $request, string $lxm): ?string
    {
        $token = self::bearerToken($request);

        if ($token === null) {
            if ($this->config->requireAuth) {
                throw new AuthenticationFailed('Valid ATProto service auth is required to access this feed.');
            }

            return null;
        }

        try {
            return $this->verifier->verify($token, $this->config->serviceDid, $lxm)->issuer;
        } catch (ServiceAuthException $e) {
            // Log the claimed issuer so an account-specific failure -- a
            // rotated signing key, a PDS the resolver cannot reach -- is
            // distinguishable in the logs from the feed simply being quiet.
            // Only when a token was actually presented; a request with no
            // Authorization header is an anonymous hit, not a failure.
            $this->logger->warning('auth failed for {issuer}: {error}', [
                'issuer' => ServiceAuthVerifier::unverifiedIssuer($token) ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            if ($this->config->requireAuth) {
                throw new AuthenticationFailed('Valid ATProto service auth is required to access this feed.');
            }

            return null;
        }
    }

    private static function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
