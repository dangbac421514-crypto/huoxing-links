<?php

namespace App\Services\Http;

use App\Contracts\DnsResolver;
use App\Exceptions\UnsafeUrl;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Throwable;

final class UrlPolicy
{
    private const MAX_URL_LENGTH = 8192;

    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Validate an outbound URL and all addresses currently returned for its
     * exact allowlisted host. The resolver is injected so tests never perform
     * ambient DNS lookups. This does not pin a later socket lookup against a
     * DNS rebind; callers rely on the fixed allowlist and repeat this policy
     * for every redirect hop.
     *
     * @param  list<mixed>  $allowedHosts
     */
    public function assertExternal(string $url, array $allowedHosts): UriInterface
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || $this->containsUnsafeUrlBytes($url) || str_contains($url, '#')) {
            throw new UnsafeUrl;
        }

        $allowed = $this->normalizeAllowlist($allowedHosts);
        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            throw new UnsafeUrl;
        }

        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());
        if ($scheme !== 'https' || $host === '' || $uri->getFragment() !== '' || $uri->getUserInfo() !== '') {
            throw new UnsafeUrl;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (! $this->isValidHost($host) || ! in_array($host, $allowed, true)) {
            throw new UnsafeUrl;
        }
        $authority = $this->rawAuthority($url);
        if ($authority === null || str_contains($authority, '@') || str_contains($authority, '%')) {
            throw new UnsafeUrl;
        }
        $this->assertRawPort($authority, $host, $uri->getPort());

        try {
            $answers = $this->dns->resolve($host);
        } catch (Throwable) {
            throw new UnsafeUrl;
        }
        if (! is_array($answers) || $answers === []) {
            throw new UnsafeUrl;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && ! $this->isPublicAddress($host)) {
            throw new UnsafeUrl;
        }

        foreach ($answers as $answer) {
            if (! is_string($answer) || ! $this->isPublicAddress($answer)) {
                throw new UnsafeUrl;
            }
        }

        return $uri;
    }

    private function containsUnsafeUrlBytes(string $url): bool
    {
        $decoded = rawurldecode($url);

        return preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1
            || str_contains($url, '\\')
            || str_contains($decoded, '\\')
            || preg_match('/%(?![0-9a-fA-F]{2})/', $url) === 1;
    }

    /** @param list<mixed> $allowedHosts */
    private function normalizeAllowlist(array $allowedHosts): array
    {
        if ($allowedHosts === []) {
            throw new UnsafeUrl;
        }

        $normalized = [];
        foreach ($allowedHosts as $allowedHost) {
            if (! is_string($allowedHost) || preg_match('/[\x00-\x1f\x7f]/', $allowedHost) === 1) {
                throw new UnsafeUrl;
            }
            $host = strtolower(trim($allowedHost));
            if (! $this->isValidHost($host) || str_contains($host, '/')) {
                throw new UnsafeUrl;
            }
            $normalized[] = $host;
        }

        return array_values(array_unique($normalized));
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/[^\x00-\x7f]/', $host) === 1 || str_ends_with($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Numeric single-label hosts can be interpreted as IPv4 integers by
        // different HTTP stacks even though PHP does not classify them as an
        // IP. Treat them as invalid rather than relying on DNS for safety.
        if (
            preg_match('/^[0-9.]+$/D', $host) === 1
            || preg_match('/^(?:0x[0-9a-f]+|0[0-7]+)(?:\.(?:0x[0-9a-f]+|0[0-7]+)){0,3}$/iD', $host) === 1
        ) {
            return false;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host) !== 1) {
            return false;
        }

        return true;
    }

    private function rawAuthority(string $url): ?string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://([^/?#]*)~iD', $url, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function assertRawPort(string $authority, string $host, ?int $parsedPort): void
    {
        $rawHost = $authority;
        $rawPort = null;
        if (str_starts_with($authority, '[')) {
            $closing = strpos($authority, ']');
            if ($closing === false) {
                throw new UnsafeUrl;
            }
            $rawHost = substr($authority, 1, $closing - 1);
            $suffix = substr($authority, $closing + 1);
            if ($suffix !== '') {
                if (! str_starts_with($suffix, ':')) {
                    throw new UnsafeUrl;
                }
                $rawPort = substr($suffix, 1);
            }
        } elseif (str_contains($authority, ':')) {
            if (substr_count($authority, ':') !== 1) {
                throw new UnsafeUrl;
            }
            [$rawHost, $rawPort] = explode(':', $authority, 2);
        }

        if (strtolower($rawHost) !== $host || ($rawPort !== null && ($rawPort === '' || ! ctype_digit($rawPort)))) {
            throw new UnsafeUrl;
        }

        if ($rawPort !== null) {
            $port = filter_var($rawPort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false || $port !== 443) {
                throw new UnsafeUrl;
            }
        } elseif ($parsedPort !== null && $parsedPort !== 443) {
            throw new UnsafeUrl;
        }
    }

    private function isPublicAddress(string $address): bool
    {
        if ($address === '' || preg_match('/[\x00-\x20\x7f]/', $address) === 1) {
            return false;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $octets = array_values(unpack('C4', $packed));
            $number = ($octets[0] * 16777216) + ($octets[1] * 65536) + ($octets[2] * 256) + $octets[3];
            foreach ([
                [0, 8],       // unspecified/current network
                [167772160, 8], // 10/8
                [1681915904, 10], // 100.64/10 carrier-grade NAT
                [2130706432, 8], // loopback
                [2851995648, 16], // 169.254/16 link-local
                [2886729728, 12], // 172.16/12
                [3221225472, 24], // 192.0.0/24
                [3221225984, 24], // 192.0.2/24 documentation
                [3223307264, 24], // 192.31.196/24 AS112
                [3224682752, 24], // 192.52.193/24 AS112
                [3227017984, 24], // 192.88.99/24 deprecated 6to4 anycast
                [3232706560, 24], // 192.175.48/24 AS112
                [3232235520, 16], // 192.168/16
                [3323068416, 15], // 198.18/15 benchmark
                [3325256704, 24], // 198.51.100/24 documentation
                [3405803776, 24], // 203.0.113/24 documentation
                [3758096384, 4], // multicast
                [4026531840, 4], // reserved
            ] as [$network, $bits]) {
                $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
                if (($number & $mask) === $network) {
                    return false;
                }
            }

            return true;
        }

        // IPv4-mapped IPv6 must be classified by the embedded IPv4 address.
        if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return $this->isPublicAddress(inet_ntop(substr($packed, 12, 4)) ?: '');
        }

        // Well-known NAT64 answers carry an IPv4 destination in the final
        // four bytes. Classify that embedded address too, so a DNS answer
        // cannot smuggle a private endpoint through an IPv6-only network.
        $nat64 = inet_pton('64:ff9b::');
        if (is_string($nat64) && $this->matchesPrefix($packed, $nat64, 96)) {
            return $this->isPublicAddress(inet_ntop(substr($packed, 12, 4)) ?: '');
        }

        foreach ([
            [inet_pton('::'), 128],
            [inet_pton('::1'), 128],
            [inet_pton('::'), 96], // IPv4-compatible and unspecified space
            [inet_pton('fc00::'), 7], // unique local
            [inet_pton('fec0::'), 10], // deprecated site-local
            [inet_pton('fe80::'), 10], // link-local
            [inet_pton('ff00::'), 8], // multicast
            [inet_pton('100::'), 64], // discard-only prefix
            [inet_pton('64:ff9b::'), 48], // network-specific NAT64 space
            [inet_pton('2001:0000::'), 32], // Teredo
            [inet_pton('2001:0001::'), 32], // protocol assignment space
            [inet_pton('2001:0002::'), 48], // benchmarking
            [inet_pton('2001:0003::'), 32], // AMT
            [inet_pton('2001:0004::'), 48], // AS112
            [inet_pton('2001:0010::'), 28], // ORCHID
            [inet_pton('2001:0020::'), 28], // ORCHIDv2
            [inet_pton('2001:db8::'), 32], // documentation
            [inet_pton('2002::'), 16], // 6to4 transition space
            [inet_pton('3ffe::'), 16], // 6bone
            [inet_pton('3fff::'), 20], // documentation prefix
        ] as [$network, $bits]) {
            if (is_string($network) && $this->matchesPrefix($packed, $network, $bits)) {
                return false;
            }
        }

        return true;
    }

    private function matchesPrefix(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        if ($bits % 8 === 0) {
            return true;
        }

        $mask = (0xFF << (8 - ($bits % 8))) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
