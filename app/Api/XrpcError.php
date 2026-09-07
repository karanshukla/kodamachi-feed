<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Http\Responses\Json;
use Tempest\Http\Status;

/**
 * XRPC errors are a JSON body of `error` and `message`. Clients switch on
 * `error`, so the name matters as much as the status code.
 */
final class XrpcError
{
    public static function make(Status $status, string $error, string $message): Json
    {
        return new Json(['error' => $error, 'message' => $message], $status);
    }

    public static function invalidRequest(string $error, string $message): Json
    {
        return self::make(Status::BAD_REQUEST, $error, $message);
    }

    public static function rateLimited(string $message): Json
    {
        return self::make(Status::TOO_MANY_REQUESTS, 'RateLimitExceeded', $message);
    }

    public static function authRequired(string $message): Json
    {
        return self::make(Status::UNAUTHORIZED, 'AuthenticationRequired', $message);
    }
}
