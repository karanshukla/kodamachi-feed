<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesDown;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\DropTableStatement;

/**
 * Resolved DID documents, cached across requests.
 *
 * A per-process cache would be useless here: every request may land in a
 * different worker, so an in-memory cache would resolve the same DID against
 * plc.directory over and over.
 */
final class CreateDidDocumentsTable implements MigratesUp, MigratesDown
{
    public string $name = '2026-09-06_did_documents';

    public function up(): QueryStatement
    {
        return new CreateTableStatement('did_documents')
            ->primary()
            ->string('did')
            ->text('document')
            ->integer('fetched_at')
            ->unique('did');
    }

    public function down(): QueryStatement
    {
        return new DropTableStatement('did_documents');
    }
}
