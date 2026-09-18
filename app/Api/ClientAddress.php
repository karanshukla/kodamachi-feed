<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Http\Request;

final class ClientAddress
{
    /**
     * The caller's address, as far as it can be known.
     *
     * Behind Railway's edge REMOTE_ADDR is the proxy, so the forwarded header
     * is what distinguishes callers. Only its last entry is trusted: that is
     * the one the edge appended, while everything before it came from the
     * client. Taking the first entry let any caller pick its own rate-limit
     * bucket per request by sending a made-up header.
     */
    public static function of(Request $request): string
    {
        $forwarded = $request->headers->get('X-Forwarded-For');

        if ($forwarded !== null && $forwarded !== '') {
            $hops = explode(',', $forwarded);
            $last = trim(end($hops));

            if ($last !== '') {
                return $last;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : 'unknown';
    }
}
