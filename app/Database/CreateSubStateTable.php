<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesDown;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\DropTableStatement;

/**
 * One row per subscription endpoint, holding the Jetstream cursor
 * (microseconds since the epoch) so a reconnect resumes where it left off
 * instead of at the live tip.
 */
final class CreateSubStateTable implements MigratesUp, MigratesDown
{
    public string $name = '2026-09-06_sub_state';

    public function up(): QueryStatement
    {
        return new CreateTableStatement('sub_state')
            ->primary()
            ->string('service')
            ->integer('cursor')
            ->unique('service');
    }

    public function down(): QueryStatement
    {
        return new DropTableStatement('sub_state');
    }
}
