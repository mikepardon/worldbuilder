<?php

declare(strict_types=1);

namespace App\Services\Dns;

/** Resolves A records via the system resolver. */
class SystemDnsResolver implements DnsResolver
{
    public function aRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_A);
        if ($records === false) {
            return [];
        }

        return collect($records)
            ->pluck('ip')
            ->filter(fn ($ip) => is_string($ip) && $ip !== '')
            ->values()
            ->all();
    }
}
