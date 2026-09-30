<?php

namespace App\Support;

final class CmsAccessPolicy
{
    private const LOCAL_HOSTS = [
        '127.0.0.1',
        'localhost',
        '1nx12grf-8080.euw.devtunnels.ms',
    ];

    private const LOCAL_PROXIES = ['172.21.0.1'];

    public static function configuration(string $environment, ?string $allowedHosts, ?string $trustedProxies): array
    {
        $local = in_array(strtolower($environment), ['local', 'testing'], true);

        return [
            'trusted_proxies' => $trustedProxies === null
                ? ($local ? self::LOCAL_PROXIES : [])
                : self::proxies($trustedProxies),
            'hosts' => $allowedHosts === null
                ? ($local ? self::LOCAL_HOSTS : [])
                : self::hosts($allowedHosts),
        ];
    }

    public static function hosts(string $value): array
    {
        return self::values($value, static function (string $host): bool {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return true;
            }

            return $host === 'localhost' || (bool) preg_match(
                '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                $host,
            );
        });
    }

    public static function proxies(string $value): array
    {
        return self::values($value, static function (string $proxy): bool {
            [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
            if (! filter_var($address, FILTER_VALIDATE_IP)) {
                return false;
            }
            if ($prefix === null) {
                return true;
            }

            $maximum = str_contains($address, ':') ? 128 : 32;

            return ctype_digit($prefix) && (int) $prefix <= $maximum;
        }, false);
    }

    private static function values(string $value, callable $valid, bool $lowercase = true): array
    {
        $result = [];
        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);
            if ($lowercase) {
                $candidate = strtolower(rtrim($candidate, '.'));
            }
            if ($candidate !== '' && ! str_contains($candidate, '*') && $valid($candidate)) {
                $result[] = $candidate;
            }
        }

        return array_values(array_unique($result));
    }
}
