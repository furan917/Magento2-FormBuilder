<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Map;

use Furan\FormBuilder\Model\Map\MapRequestPolicy;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class MapRequestPolicyTest extends TestCase
{
    private function policy(mixed $ports): \Furan\FormBuilder\Api\Data\RequestPolicy
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(
            static fn (string $path, $default = null) => $path === 'furan_formbuilder/map/ports' ? $ports : $default
        );

        return (new MapRequestPolicy($config))->build();
    }

    public function testItIsHttpsOnlyWithTheFixedLimitsAndNoWebhookBypasses(): void
    {
        $policy = $this->policy(null);

        $this->assertFalse($policy->allowHttp);
        $this->assertSame([443], $policy->ports);
        $this->assertSame([], $policy->ipLiteralHosts);
        $this->assertSame([], $policy->allowCidrs);
        $this->assertFalse($policy->decodeNat64);
        $this->assertSame(5000, $policy->connectTimeoutMs);
        $this->assertSame(5000, $policy->timeoutMs);
        $this->assertSame(262144, $policy->maxResponseBytes);
    }

    public function testItAddsValidEnvPhpPortsAndIgnoresInvalidEntries(): void
    {
        $this->assertSame([443, 8443, 9443], $this->policy([8443, '9443', 'x', 0, 70000, null, 443.5, [1]])->ports);
        $this->assertSame([443], $this->policy('8443')->ports);
    }
}
