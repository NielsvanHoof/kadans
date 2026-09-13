<?php

declare(strict_types=1);

namespace App\ClientPortal\Support\Session;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

use function hash;
use function implode;
use function is_string;
use function preg_replace;

/**
 * A deliberately coarse "same device, same network" signal for portal grants.
 *
 * Coarse because the alternative is worse. A full user-agent string changes on
 * every browser update and an exact IP changes when a phone moves from wifi to
 * mobile data, so a precise fingerprint would alert constantly and be ignored.
 *
 * Two normalisations:
 *
 *  - Version numbers are stripped from the user agent, so "Chrome/121" and
 *    "Chrome/122" collapse to the same family.
 *  - The address is reduced to its network with Symfony's `IpUtils::anonymize()`
 *    — /24 for IPv4, /64 for IPv6 — so a DHCP lease change within one network
 *    stays recognised. It also strips an RFC 4007 scope (`fe80::1%eth0`), which
 *    would otherwise fold a server interface name into the fingerprint.
 *
 * The result is hashed and only the hash is persisted: this is a signal about a
 * credential, not a record of a client's location or hardware.
 */
final readonly class PortalDeviceFingerprint
{
    public static function fromRequest(Request $request): string
    {
        return hash('sha256', implode('|', [
            self::userAgentFamily($request->userAgent()),
            self::networkPrefix($request->ip()),
        ]));
    }

    private static function userAgentFamily(?string $userAgent): string
    {
        // Request::userAgent() is string|null; empty and non-string both map to the same sentinel.
        if (! is_string($userAgent) || $userAgent === '') { // @pest-mutate-ignore
            return 'unknown-agent';
        }

        // Drop version numbers and their separators; keep the product names.
        // Truncation bound is a safety cap, not behaviour under test.
        $family = preg_replace('/[\d]+(?:[._][\d]+)*/', '', mb_substr($userAgent, 0, 512)); // @pest-mutate-ignore

        return is_string($family) ? $family : 'unknown-agent'; // @pest-mutate-ignore
    }

    private static function networkPrefix(?string $ip): string
    {
        // Same sentinel collapse as u serAgentFamily for missing/blank client IPs.
        if (! is_string($ip) || $ip === '') { // @pest-mutate-ignore
            return 'unknown-network';
        }

        return IpUtils::anonymize($ip);
    }
}
