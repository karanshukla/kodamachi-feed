<?php

declare(strict_types=1);

namespace App\Api;

use App\Feed\FeedService;
use Tempest\Http\Responses\Json;
use Tempest\Router\Get;

final readonly class DescribeFeedGeneratorController
{
    public function __construct(
        private FeedService $feedService,
    ) {}

    #[Get('/xrpc/app.bsky.feed.describeFeedGenerator')]
    public function __invoke(): Json
    {
        return new Json($this->feedService->describeFeed()->toArray());
    }
}
