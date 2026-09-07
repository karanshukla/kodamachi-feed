<?php

declare(strict_types=1);

use Tempest\Database\Config\SQLiteConfig;

use function Tempest\env;

/**
 * In production this must point at a persistent volume. Railway's filesystem
 * is ephemeral, so a path outside a mounted volume is wiped on every deploy
 * and the feed comes back empty with its cursor at the start of the stream.
 */
$location = (string) env('FEEDGEN_SQLITE_LOCATION', 'var/database.sqlite');

// Resolved against the project root, not the working directory: the console
// runs from the project root while the web server runs from public/, and a
// relative path would otherwise mean two different databases.
if (!str_starts_with($location, '/') && !str_starts_with($location, ':')) {
    $location = dirname(__DIR__, 2) . '/' . $location;
}

return new SQLiteConfig(path: $location);
