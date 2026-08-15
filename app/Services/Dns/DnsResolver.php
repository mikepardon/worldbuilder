<?php

declare(strict_types=1);

namespace App\Services\Dns;

/** The boundary to DNS lookups, so custom-domain verification can be tested without real DNS. */
interface DnsResolver
{
    /**
     * The IPv4 addresses a hostname's A records resolve to.
     *
     * @return list<string>
     */
    public function aRecords(string $host): array;
}
