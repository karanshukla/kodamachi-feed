<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Http\IsRequest;
use Tempest\Http\Request;

final class GetFeedSkeletonRequest implements Request
{
    use IsRequest;

    public string $feed = '';

    public ?int $limit = null;

    public ?string $cursor = null;
}
