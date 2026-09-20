<?php

declare(strict_types=1);

namespace App\Framework;

use App\ServiceAuth\ServiceAuthVerifier;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use KaranShukla\PhpAtprotoIdentity\HttpDidDocumentResolver;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

use function Tempest\env;

final class ServiceAuthVerifierInitializer implements Initializer
{
    public function initialize(Container $container): ServiceAuthVerifier
    {
        return new ServiceAuthVerifier(
            new HttpDidDocumentResolver(
                httpClient: new GuzzleClient([
                    // A slow directory must not hold a feed request open until
                    // the AppView gives up on us.
                    'timeout' => 5,
                    'connect_timeout' => 3,
                ]),
                requestFactory: new HttpFactory(),
                cache: new DatabaseDidDocumentCache(),
                plcDirectory: (string) env('FEEDGEN_PLC_DIRECTORY', 'https://plc.directory'),
            ),
        );
    }
}
