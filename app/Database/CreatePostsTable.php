<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesDown;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\DropTableStatement;

final class CreatePostsTable implements MigratesUp, MigratesDown
{
    public string $name = '2026-09-06_posts';

    public function up(): QueryStatement
    {
        return new CreateTableStatement('posts')
            ->primary()
            ->string('uri')
            ->string('cid')
            // Milliseconds since the epoch. Stored as an integer so the feed
            // cursor is a plain numeric comparison rather than string date
            // ordering.
            ->integer('indexed_at')
            ->unique('uri')
            ->index('indexed_at');
    }

    public function down(): QueryStatement
    {
        return new DropTableStatement('posts');
    }
}
