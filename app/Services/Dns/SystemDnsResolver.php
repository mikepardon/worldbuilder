<?php

declare(strict_types=1);

namespace App\Services\Dns;

/** Resolves A records via the system resolver. */
class SystemDnsResolver implements DnsResolver
{
    public function aRecords(string $host): array
    {
        // A false return is a resolver failure and an empty array means "no A records"; both are treated the
        // same here — as "not pointing at us yet". This is the custom-domain verify flow, where an
        // unresolvable/unpropagated/mistyped host is the expected not-ready state the caller reports to the
        // user, so it is deliberately NOT sent to Sentry. The `@` suppresses the lookup-failure warning.
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
