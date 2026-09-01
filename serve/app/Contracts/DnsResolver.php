<?php

namespace App\Contracts;

interface DnsResolver
{
    /**
     * @return list<string> A/AAAA address answers. An empty list is a failure
     *                      for callers enforcing an outbound URL policy.
     */
    public function resolve(string $host): array;
}
