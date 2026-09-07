<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tempest\Framework\Testing\IntegrationTest;

abstract class IntegrationTestCase extends IntegrationTest
{
    protected string $root = __DIR__ . '/../../';

    protected function setUp(): void
    {
        parent::setUp();

        $this->database->setup();
    }
}
