<?php

namespace App\Services\Http;

use App\Contracts\DnsResolver;

final class NativeDnsResolver implements DnsResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $answers = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $address = match ($record['type'] ?? null) {
                'A' => $record['ip'] ?? null,
                'AAAA' => $record['ipv6'] ?? null,
                default => null,
            };
            if (is_string($address)) {
                $answers[] = $address;
            }
        }

        return array_values(array_unique($answers));
    }
}
