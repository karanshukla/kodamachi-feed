<?php

declare(strict_types=1);

namespace Tests\Unit\Framework;

use Aazsamir\Libphpsky\Client\AuthAwareClient;
use Aazsamir\Libphpsky\Client\AuthConfig;
use Aazsamir\Libphpsky\Model\Meta\ATProtoMetaClient;
use App\Feed\FeedConfig;
use App\Framework\ATProtoMetaClientInitializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;

/**
 * @internal
 */
final class ATProtoMetaClientInitializerTest extends TestCase
{
    #[Test]
    public function builds_a_client_for_an_account_on_a_third_party_pds(): void
    {
        $container = new GenericContainer()
            ->singleton(FeedConfig::class, new FeedConfig(
                hostname: 'feed.test',
                serviceDid: 'did:web:feed.test',
                publisherDid: 'did:plc:publisher',
                textTerms: ['kodamachi'],
                altTerms: ['kodamachi'],
                pdsUrl: 'https://eurosky.social',
            ))
            ->singleton(AuthConfig::class, new AuthConfig(login: 'feed.test', password: 'secret'));

        $client = new ATProtoMetaClientInitializer()->initialize($container);

        self::assertInstanceOf(ATProtoMetaClient::class, $client);
        self::assertInstanceOf(AuthAwareClient::class, $client->getClient());
    }
}
