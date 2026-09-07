<?php

declare(strict_types=1);

namespace App\ServiceAuth;

use RuntimeException;

/**
 * Raised whenever a service-auth token cannot be accepted.
 *
 * Callers that treat auth as optional catch this and fall back to an
 * anonymous request; callers that require auth turn it into a 401.
 */
class ServiceAuthException extends RuntimeException {}
