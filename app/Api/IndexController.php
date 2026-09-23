<?php

declare(strict_types=1);

namespace App\Api;

use App\Feed\FeedConfig;
use Tempest\Router\Get;

final readonly class IndexController
{
    public function __construct(
        private FeedConfig $config,
    ) {}

    #[Get('/')]
    public function __invoke(): Json
    {
        return new Json([
            'name' => $this->config->recordKey,
            'description' => $this->config->description,
            'feed' => $this->config->feedUri(),
        ]);
    }
}
