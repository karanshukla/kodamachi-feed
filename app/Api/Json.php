<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Http\IsResponse;
use Tempest\Http\Response;
use Tempest\Http\Status;

/**
 * Tempest's own Json response sets Content-Type, and GenericResponseSender
 * adds another for any array body, so every response went out with the
 * header twice. The AppView rejected the skeleton as InvalidFeedResponse.
 * This leaves Content-Type to the sender, which adds it exactly once.
 */
final class Json implements Response
{
    use IsResponse;

    /** @param array<string, string> $headers */
    public function __construct(array $body, ?Status $status = null, array $headers = [])
    {
        $this->status = $status ?? Status::OK;
        $this->body = $body;
        $this->addHeaders($headers);
    }
}
