<?php

declare(strict_types=1);

namespace App\Framework;

use Aazsamir\Libphpsky\Client\ATProtoClient;
use Aazsamir\Libphpsky\Client\ATProtoClientBuilder;
use Aazsamir\Libphpsky\Client\AuthAwareClient;
use Aazsamir\Libphpsky\Client\AuthConfig;
use Aazsamir\Libphpsky\Client\ErrorAwareClient;
use Aazsamir\Libphpsky\Generator\Prefab\TypeResolver;
use Aazsamir\Libphpsky\Model\Com\Atproto\Server\CreateSession\CreateSession;
use Aazsamir\Libphpsky\Model\Com\Atproto\Server\RefreshSession\RefreshSession;
use Aazsamir\Libphpsky\Model\Meta\ATProtoMetaClient;
use App\Feed\FeedConfig;
use GuzzleHttp\Client as GuzzleClient;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ATProtoMetaClientInitializer implements Initializer
{
    private const string DEFAULT_PDS = 'https://bsky.social';

    public function initialize(Container $container): ATProtoMetaClient
    {
        $feedConfig = $container->get(FeedConfig::class);
        $authConfig = $container->get(AuthConfig::class);

        // ::default() rather than the constructor: passing a client to the
        // constructor still trips libphpsky's "without arguments" deprecation,
        // which Tempest escalates to an exception outside production.
        return ATProtoMetaClient::default(
            $feedConfig->pdsUrl === self::DEFAULT_PDS
                ? ATProtoClientBuilder::default()
                    ->authConfig($authConfig)
                    // Feed data is served from our own database; the only calls
                    // through this client are the backfill search and record
                    // writes, neither of which wants a cached answer.
                    ->useQueryCache(false)
                    ->build()
                : self::clientForPds($feedConfig->pdsUrl, $authConfig),
        );
    }

    /**
     * The builder points session creation at bsky.social, which is wrong for
     * an account on a third-party PDS: createSession has to go to the PDS that
     * holds the account. Assembling the stack by hand is the only way to aim
     * it somewhere else.
     */
    private static function clientForPds(string $pdsUrl, AuthConfig $authConfig): AuthAwareClient
    {
        $transport = new ErrorAwareClient(new ATProtoClient(new GuzzleClient()));
        $types = TypeResolver::getDefault();

        return new AuthAwareClient(
            decorated: $transport,
            authConfig: $authConfig,
            sessionStore: new ATProtoClientBuilder()->defaultSessionStore(),
            createSession: new CreateSession($transport, $types)->withEndpoint($pdsUrl),
            refreshSession: new RefreshSession($transport, $types)->withEndpoint($pdsUrl),
        );
    }
}
