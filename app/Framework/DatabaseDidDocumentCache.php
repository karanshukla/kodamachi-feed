<?php

declare(strict_types=1);

namespace App\Framework;

use KaranShukla\PhpAtprotoIdentity\Resolution\Cache\DidDocumentCache;

use function Tempest\Database\query;

final class DatabaseDidDocumentCache implements DidDocumentCache
{
    public function get(string $did): ?array
    {
        $row = query('did_documents')
            ->select('document', 'fetched_at')
            ->where('did = ?', $did)
            ->first();

        if ($row === null) {
            return null;
        }

        $document = json_decode((string) $row['document'], true);

        if (!is_array($document)) {
            return null;
        }

        /** @var array<string, mixed> $document */
        return [
            'document' => $document,
            'age' => max(0, time() - (int) $row['fetched_at']),
        ];
    }

    public function put(string $did, array $document): void
    {
        $encoded = json_encode($document, JSON_THROW_ON_ERROR);
        $now = time();

        $exists = query('did_documents')
            ->select('did')
            ->where('did = ?', $did)
            ->first() !== null;

        if ($exists) {
            query('did_documents')
                ->update(document: $encoded, fetched_at: $now)
                ->where('did = ?', $did)
                ->execute();

            return;
        }

        query('did_documents')
            ->insert(['did' => $did, 'document' => $encoded, 'fetched_at' => $now])
            ->execute();
    }

    /**
     * Drops documents too old to be served even as a fallback, so the table
     * does not keep one row for every requester the feed has ever seen.
     * Called from the collector's housekeeping, not from a request.
     */
    public function forget(int $olderThanSeconds): void
    {
        query('did_documents')
            ->delete()
            ->where('fetched_at < ?', time() - $olderThanSeconds)
            ->execute();
    }
}
