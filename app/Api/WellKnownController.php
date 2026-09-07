<?php

declare(strict_types=1);

namespace App\Api;

use App\Feed\FeedConfig;
use Tempest\Http\Responses\Json;
use Tempest\Router\Get;

final readonly class WellKnownController
{
    public function __construct(
        private FeedConfig $config,
    ) {}

    #[Get('/.well-known/did.json')]
    public function __invoke(): Json
    {
        return new Json(
            [
                '@context' => ['https://www.w3.org/ns/did/v1'],
                'id' => $this->config->serviceDid,
                'service' => [
                    [
                        'id' => '#bsky_fg',
                        'type' => 'BskyFeedGenerator',
                        'serviceEndpoint' => 'https://' . $this->config->hostname,
                    ],
                ],
            ],
            headers: ['Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400'],
        );
    }
}
