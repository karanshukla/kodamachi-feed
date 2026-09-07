<?php

declare(strict_types=1);

use Tempest\Log\Config\SimpleLogConfig;

use function Tempest\env;

/**
 * Logs go to stdout so the container runtime collects them. A log file inside
 * the image would be invisible in `railway logs` and lost on redeploy.
 */
return new SimpleLogConfig(
    path: (string) env('FEEDGEN_LOG_PATH', 'php://stdout'),
    prefix: (string) env('ENVIRONMENT', 'production'),
);
