<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Http\Request;

final class ClientAddress
{
    /**
     * The caller's address, as far as it can be known.
     *
     * Behind Caddy (and behind Railway's edge in front of that) REMOTE_ADDR is
     * the proxy, so the forwarded header is what distinguishes callers. It is
     * client-controlled and therefore spoofable; it is used only to spread
     * rate-limit buckets, never for authorization.
     */
    public static function of(Request $request): string
    {
        $forwarded = $request->headers->get('X-Forwarded-For');

        if ($forwarded !== null && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);

            if ($first !== '') {
                return $first;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : 'unknown';
    }
}
