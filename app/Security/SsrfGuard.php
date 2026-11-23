<?php

namespace App\Security;

use InvalidArgumentException;

class SsrfGuard
{
    /**
     * Validate that a tenant-provided external URL uses HTTPS and resolves only to public IPs.
     */
    public function assertSafeExternalHttpsUrl(string $url, bool $resolveDns = true): string
    {
        $trimmed = trim($url);
        $parts = parse_url($trimmed);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('URL must be a valid absolute HTTPS address.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('Only HTTPS URLs are permitted for external integrations.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Embedded credentials in URLs are not permitted.');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('Local and internal hostnames are not permitted.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($host);

            return rtrim($trimmed, '/');
        }

        if ($resolveDns) {
            $resolvedIps = gethostbynamel($host);

            if ($resolvedIps === false || $resolvedIps === []) {
                throw new InvalidArgumentException('Hostname could not be resolved to an IP address.');
            }

            foreach ($resolvedIps as $ip) {
                $this->assertPublicIp($ip);
            }
        }

        return rtrim($trimmed, '/');
    }

    public function assertPublicIp(string $ip): void
    {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($isPublic === false) {
            throw new InvalidArgumentException('Target address resolves to a private, loopback, link-local, or reserved IP range.');
        }
    }
}
