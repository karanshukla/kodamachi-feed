<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesDown;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\DropTableStatement;

/**
 * Request counters, shared across workers for the same reason the DID cache is.
 */
final class CreateRateLimitsTable implements MigratesUp, MigratesDown
{
    public string $name = '2026-09-06_rate_limits';

    public function up(): QueryStatement
    {
        return new CreateTableStatement('rate_limits')
            ->primary()
            ->string('bucket')
            ->integer('hits')
            ->integer('window_start')
            ->unique('bucket');
    }

    public function down(): QueryStatement
    {
        return new DropTableStatement('rate_limits');
    }
}
