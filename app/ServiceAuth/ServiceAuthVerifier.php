<?php

declare(strict_types=1);

namespace App\ServiceAuth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Throwable;

/**
 * Verifies an ATProto service-auth JWT.
 *
 * A service-auth token is signed by the *user's* signing key, published in
 * their DID document under the `#atproto` verification method, so verifying
 * one means resolving the issuer's DID and checking the signature against
 * every key it lists.
 *
 * Two compatibility notes, both learned the hard way from clients that
 * suddenly saw an empty feed:
 *
 * - `lxm` postdates the original service-auth spec. A token minted without it
 *   is not a token for the wrong method, it is a token from an older
 *   implementation, so an absent claim is tolerated while a present-and-wrong
 *   one is still rejected.
 * - A signing key rotation invalidates a cached DID document. If the
 *   signature fails against every cached key, the document is refetched once
 *   and the signature retried, so a rotation costs one extra request rather
 *   than an hour of 401s.
 */
final readonly class ServiceAuthVerifier
{
    public function __construct(
        private DidDocumentResolver $resolver,
        private int $leeway = 60,
    ) {}

    /**
     * @param string $audience the service DID this endpoint answers to
     * @param string|null $lxm the NSID being called, or null to skip the method check
     *
     * @throws ServiceAuthException
     */
    public function verify(string $jwt, string $audience, ?string $lxm = null): VerifiedToken
    {
        $claims = self::decodeClaims($jwt);

        $issuer = $claims['iss'] ?? null;

        if (!is_string($issuer) || !str_starts_with($issuer, 'did:')) {
            throw new ServiceAuthException('Token has no DID issuer');
        }

        if (($claims['aud'] ?? null) !== $audience) {
            throw new ServiceAuthException('Token audience does not match this service');
        }

        if (!isset($claims['exp'])) {
            throw new ServiceAuthException('Token has no expiry');
        }

        $tokenLxm = $claims['lxm'] ?? null;

        if ($lxm !== null && is_string($tokenLxm) && $tokenLxm !== $lxm) {
            throw new ServiceAuthException("Token was minted for {$tokenLxm}, not {$lxm}");
        }

        // `iss` may carry a service fragment (did:plc:abc#atproto_labeler).
        // The document to resolve is the bare DID's; with the fragment left
        // on, a did:web issuer resolves against a hostname ending in "#...".
        $this->checkSignature($jwt, explode('#', $issuer, 2)[0]);

        return new VerifiedToken(
            issuer: $issuer,
            audience: $audience,
            lxm: is_string($tokenLxm) ? $tokenLxm : null,
            claims: $claims,
        );
    }

    /**
     * Reads the issuer out of a token without verifying anything.
     *
     * For logs only: it says which account a rejected token claimed to come
     * from, which is what makes an account-specific auth failure visible
     * rather than looking like a stale feed.
     */
    public static function unverifiedIssuer(string $jwt): ?string
    {
        try {
            $issuer = self::decodeClaims($jwt)['iss'] ?? null;
        } catch (ServiceAuthException) {
            return null;
        }

        return is_string($issuer) ? $issuer : null;
    }

    private function checkSignature(string $jwt, string $issuer): void
    {
        $lastError = null;

        foreach ([false, true] as $forceRefresh) {
            $keys = $this->signingKeys($issuer, $forceRefresh);

            foreach ($keys as $key) {
                try {
                    JWT::$leeway = $this->leeway;
                    JWT::decode($jwt, new Key($key->pem(), $key->algorithm()));

                    return;
                } catch (Throwable $e) {
                    $lastError = $e;
                }
            }

            // An expired or malformed token will not verify against a fresher
            // copy of the document either, so only a signature mismatch is
            // worth a second resolution.
            if (!$lastError instanceof SignatureInvalidException) {
                break;
            }
        }

        throw new ServiceAuthException(
            $lastError !== null ? $lastError->getMessage() : "No signing key published for {$issuer}",
            previous: $lastError,
        );
    }

    /**
     * @return list<VerificationKey>
     */
    private function signingKeys(string $did, bool $forceRefresh): array
    {
        $document = $this->resolver->resolve($did, $forceRefresh);
        $methods = $document['verificationMethod'] ?? [];
        $keys = [];

        if (!is_array($methods)) {
            return [];
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $id = $method['id'] ?? '';
            $multibase = $method['publicKeyMultibase'] ?? null;

            if (!is_string($id) || !str_ends_with($id, '#atproto') || !is_string($multibase)) {
                continue;
            }

            try {
                $keys[] = DidKey::fromMultibase($multibase);
            } catch (ServiceAuthException) {
                // An unsupported key type is not fatal on its own; another
                // verification method may still be usable.
                continue;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeClaims(string $jwt): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new ServiceAuthException('Malformed token');
        }

        $json = base64_decode(strtr($segments[1], '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);

        if (!is_array($claims)) {
            throw new ServiceAuthException('Token payload is not a JSON object');
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }
}
