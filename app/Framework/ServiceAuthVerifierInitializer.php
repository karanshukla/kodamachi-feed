<?php

declare(strict_types=1);

namespace App\Framework;

use App\ServiceAuth\RefreshCooldownResolver;
use App\ServiceAuth\ServiceAuthVerifier;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use KaranShukla\PhpAtprotoIdentity\Resolution\HttpDidDocumentResolver;
use Psr\Http\Message\RequestInterface;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

use function Tempest\env;

final class ServiceAuthVerifierInitializer implements Initializer
{
    public function initialize(Container $container): ServiceAuthVerifier
    {
        $cache = new DatabaseDidDocumentCache();
        $plcDirectory = (string) env('FEEDGEN_PLC_DIRECTORY', 'https://plc.directory');

        $stack = HandlerStack::create();
        $stack->push(self::publicHttpsMiddleware($plcDirectory), 'public_https_only');

        return new ServiceAuthVerifier(
            new RefreshCooldownResolver(
                new HttpDidDocumentResolver(
                    httpClient: new GuzzleClient([
                        'handler' => $stack,
                        // A slow directory must not hold a feed request open until
                        // the AppView gives up on us.
                        'timeout' => 5,
                        'connect_timeout' => 3,
                        'allow_redirects' => false,
                    ]),
                    requestFactory: new HttpFactory(),
                    cache: $cache,
                    plcDirectory: $plcDirectory,
                ),
                $cache,
            ),
        );
    }

    /**
     * Rejects non-HTTPS requests and hosts resolving to private, loopback,
     * link-local, or reserved IP ranges during did:web resolution.
     */
    public static function publicHttpsMiddleware(string $plcDirectory = 'https://plc.directory'): Closure
    {
        $plcHost = strtolower((string) parse_url($plcDirectory, PHP_URL_HOST));

        return static function (callable $handler) use ($plcHost): Closure {
            return static function (RequestInterface $request, array $options) use ($handler, $plcHost) {
                $uri = $request->getUri();

                if (strtolower($uri->getScheme()) !== 'https') {
                    throw new ConnectException('DID resolution requires HTTPS', $request);
                }

                $host = strtolower(rtrim(trim($uri->getHost(), '[]'), '.'));

                if ($host === '') {
                    throw new ConnectException('DID resolution request has no host', $request);
                }

                if ($host !== $plcHost) {
                    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                        if (!self::isPublicIp($host)) {
                            throw new ConnectException("Refusing to connect to non-public IP {$host}", $request);
                        }
                    } else {
                        $ips = self::resolveHostIps($host);

                        if ($ips === []) {
                            throw new ConnectException("Could not resolve host {$host}", $request);
                        }

                        foreach ($ips as $ip) {
                            if (!self::isPublicIp($ip)) {
                                throw new ConnectException("Host {$host} resolved to non-public IP {$ip}", $request);
                            }
                        }
                    }
                }

                return $handler($request, $options);
            };
        };
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host);

            if (is_array($v4)) {
                foreach ($v4 as $ip) {
                    if (is_string($ip)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $first = ord($packed[0]);
            $second = ord($packed[1]);

            // 100.64.0.0/10 (Carrier-Grade NAT) and 169.254.0.0/16 (link-local)
            if ($first === 100 && ($second & 0xC0) === 64) {
                return false;
            }

            if ($first === 169 && $second === 254) {
                return false;
            }

            return true;
        }

        if (strlen($packed) === 16) {
            $first = ord($packed[0]);
            $second = ord($packed[1]);

            // Only global unicast (2000::/3) is public; reject 2002::/16 (6to4),
            // 2001:db8::/32 (documentation), and 2001:0000::/32 (Teredo).
            if (($first & 0xE0) !== 0x20) {
                return false;
            }

            if ($first === 0x20 && $second === 0x02) {
                return false;
            }

            if ($first === 0x20 && $second === 0x01) {
                $third = ord($packed[2]);
                $fourth = ord($packed[3]);

                if (($third === 0x00 && $fourth === 0x00) || ($third === 0x0D && $fourth === 0xB8)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
