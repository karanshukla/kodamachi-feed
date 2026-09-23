<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Api\Json;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tempest\Container\GenericContainer;
use Tempest\Http\Responses\Json as TempestJson;
use Tempest\Router\GenericResponseSender;
use Tempest\View\ViewRenderer;

/**
 * @internal
 */
final class JsonTest extends TestCase
{
    #[Test]
    public function is_sent_with_exactly_one_content_type(): void
    {
        self::assertSame(['Content-Type: application/json'], $this->contentTypeLines(new Json(['feed' => []])));
    }

    #[Test]
    public function tempests_own_json_response_sends_it_twice(): void
    {
        self::assertCount(2, $this->contentTypeLines(new TempestJson(['feed' => []])));
    }

    /** @return list<string> */
    private function contentTypeLines(object $response): array
    {
        $sender = new GenericResponseSender(new GenericContainer(), $this->createStub(ViewRenderer::class));
        $lines = iterator_to_array(new ReflectionMethod($sender, 'resolveHeaders')->invoke($sender, $response), false);

        return array_values(array_filter($lines, static fn (string $line) => stripos($line, 'Content-Type:') === 0));
    }
}
