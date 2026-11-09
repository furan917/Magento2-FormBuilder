<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Api\Data;

use Furan\FormBuilder\Api\Data\RequestPolicy;
use PHPUnit\Framework\TestCase;

class RequestPolicyTest extends TestCase
{
    public function testItDefaultsToTheStrictestSettings(): void
    {
        $policy = new RequestPolicy();
        $this->assertFalse($policy->allowHttp);
        $this->assertSame([443], $policy->ports);
        $this->assertSame([], $policy->ipLiteralHosts);
        $this->assertSame([], $policy->hostAllowlist);
        $this->assertSame([], $policy->allowCidrs);
        $this->assertFalse($policy->decodeNat64);
        $this->assertSame(3000, $policy->connectTimeoutMs);
        $this->assertSame(10000, $policy->timeoutMs);
        $this->assertSame(65536, $policy->maxResponseBytes);
    }

    public function testItCapsTimeoutsAndResponseSize(): void
    {
        $low = new RequestPolicy(connectTimeoutMs: 1, timeoutMs: 5, maxResponseBytes: 10);
        $this->assertSame([1000, 1000, 1024], [$low->connectTimeoutMs, $low->timeoutMs, $low->maxResponseBytes]);
        $high = new RequestPolicy(connectTimeoutMs: 999999, timeoutMs: 999999, maxResponseBytes: PHP_INT_MAX);
        $this->assertSame([30000, 30000, 1048576], [$high->connectTimeoutMs, $high->timeoutMs, $high->maxResponseBytes]);
    }

    public function testItDropsInvalidEntriesInsteadOfWideningThePolicy(): void
    {
        $policy = new RequestPolicy(
            ports: [443, 0, 65536, -1, 8443, 443],
            ipLiteralHosts: ['127.0.0.1', '127.1', '[::1]', 'example.com', '0:0:0:0:0:0:0:1', 'fe80::1%eth0'],
            hostAllowlist: ['Example.com', '*.Example.org', '*', '*.', 'bad host', 'a*.com', '', '*.*.com'],
            allowCidrs: ['10.0.0.0/8', '10.0.0.0/33', 'junk', '::1/128', '::1/129', '10.0.0.0', '10.0.0.0/-1']
        );
        $this->assertSame([443, 8443], $policy->ports);
        $this->assertSame(['127.0.0.1', '::1'], $policy->ipLiteralHosts);
        $this->assertSame(['example.com', '*.example.org'], $policy->hostAllowlist);
        $this->assertSame(['10.0.0.0/8', '::1/128'], $policy->allowCidrs);
    }
}
