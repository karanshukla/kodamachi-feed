<?php

declare(strict_types=1);

namespace Tests\Support;

use Aazsamir\Libphpsky\Client\ATProtoClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers libphpsky's requests from a script and keeps them, so a test can
 * assert on where a call went and what it carried without touching the
 * network.
 */
final class RecordingATProtoClient implements ATProtoClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<array<string, mixed>> $responses JSON bodies served in order, the last one repeating */
    public function __construct(
        private readonly array $responses,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $index = min(count($this->requests), count($this->responses) - 1);
        $this->requests[] = $request;

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($this->responses[$index], JSON_THROW_ON_ERROR));
    }
}
