<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Http\PublicAddress;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Http\UrlNormalizer;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use Furan\FormBuilder\Test\Unit\Model\Http\FakeCurlSession;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UrlPolicyTest extends TestCase
{
    private const PREFIX = 'furan_formbuilder/webhooks/';

    /** @var array<string,mixed> */
    private array $env = [];
    private string $mode = State::MODE_DEVELOPER;
    /** @var string[] */
    private array $records = ['93.184.216.34'];
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function policy(?LoggerInterface $logger = null): UrlPolicy
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(
            fn (?string $key = null, mixed $default = null): mixed => $this->env[$key] ?? $default
        );
        $state = $this->createStub(State::class);
        $state->method('getMode')->willReturn($this->mode);
        $records = $this->records;
        $client = new SafeHttpClient(
            new FakeCurlSession(),
            new PublicAddress(static fn (string $host): array => $records)
        );
        return new UrlPolicy($config, $state, new UrlNormalizer(), $client, $logger ?? $this->logger);
    }

    private function set(string $key, mixed $value): void
    {
        $this->env[self::PREFIX . $key] = $value;
    }

    private function rejects(string $url, string $reason, ?UrlPolicy $policy = null): void
    {
        try {
            ($policy ?? $this->policy())->validateForSave($url);
            $this->fail("Expected $reason for $url");
        } catch (UnsafeUrlException $e) {
            $this->assertSame($reason, $e->getReason());
        }
    }

    public function testItAllowsOnlyHttpsOnPort443ByDefault(): void
    {
        $this->assertSame('https://hooks.example.com/in', $this->policy()->validateForSave('https://hooks.example.com/in')->toString());
        $this->rejects('http://hooks.example.com/in', 'scheme');
        $this->rejects('https://hooks.example.com:8443/in', 'port');
    }

    public function testItAllowsHttpOnlyWhenTheEnvPhpFlagIsOn(): void
    {
        $this->set('allow_http', true);
        $this->set('ports', [80]);
        $this->assertSame('http://hooks.example.com/in', $this->policy()->validateForSave('http://hooks.example.com/in')->toString());
        $this->set('allow_http', 'yes');
        $this->rejects('http://hooks.example.com/in', 'scheme');
    }

    public function testItRestrictsHostsToTheEnvPhpAllowlistWhenSet(): void
    {
        $this->set('host_allowlist', ['*.crm.example.com']);
        $policy = $this->policy();
        $this->assertSame('https://a.crm.example.com/', $policy->validateForSave('https://a.crm.example.com/')->toString());
        $this->rejects('https://hooks.example.com/', 'host_not_allowed', $policy);
        $this->expectException(UnsafeUrlException::class);
        $policy->validateForSend('https://hooks.example.com/');
    }

    public function testItRejectsIpLiteralUrlsUnlessListedInEnvPhp(): void
    {
        $this->rejects('https://93.184.216.34/in', 'ip_literal_not_allowed');
        $this->set('ip_literal_hosts', ['93.184.216.34']);
        $this->assertSame('93.184.216.34', $this->policy()->validateForSave('https://93.184.216.34/in')->host);
    }

    public function testItReportsWebhooksDisabledWhenTheEnvPhpKillSwitchIsOff(): void
    {
        $this->assertTrue($this->policy()->isEnabled());
        $this->set('enabled', false);
        $this->assertFalse($this->policy()->isEnabled());
        $this->set('enabled', 'false');
        $this->assertTrue($this->policy()->isEnabled());
    }

    public function testItAllowsAPrivateTestReceiverOnlyForCidrsListedInEnvPhpAndNeverByDefault(): void
    {
        $this->records = ['10.1.2.3'];
        $this->set('host_allowlist', []);
        try {
            $this->policy()->validateForSave('https://hooks.example.com/in');
            $this->fail('private address accepted by default');
        } catch (UnsafeUrlException $e) {
            $this->assertSame('address_not_public', $e->getReason());
        }
        $this->set('allow_cidrs', ['10.0.0.0/8']);
        $this->assertSame('hooks.example.com', $this->policy()->validateForSave('https://hooks.example.com/in')->host);
        $this->records = ['10.1.2.3', '192.168.0.1'];
        $this->rejects('https://hooks.example.com/in', 'address_not_public');
    }

    public function testItRejectsAnUnresolvedHostAtSave(): void
    {
        $this->records = [];
        $this->rejects('https://hooks.example.com/in', 'host_unresolved');
    }

    public function testItFallsBackToSecureDefaultsAndLogsOnceForMalformedValues(): void
    {
        $this->set('ports', 'oops');
        $this->set('allow_cidrs', ['nonsense']);
        $this->set('timeout_ms', 'x');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning')
            ->with($this->logicalNot($this->stringContains('oops')));
        $policy = $this->policy($logger);
        $this->assertSame([443], $policy->policy()->ports);
        $this->assertSame([], $policy->policy()->allowCidrs);
        $this->assertSame(10000, $policy->policy()->timeoutMs);
        $policy->policy();
    }

    public function testItIgnoresAdminConfigurationForEverySwitch(): void
    {
        $constructor = (new \ReflectionClass(UrlPolicy::class))->getConstructor();
        $this->assertNotNull($constructor);
        foreach ($constructor->getParameters() as $param) {
            $this->assertStringNotContainsString('ScopeConfig', (string)$param->getType());
        }
        $this->assertStringNotContainsString('ScopeConfig', (string)file_get_contents(
            (string)(new \ReflectionClass(UrlPolicy::class))->getFileName()
        ));
    }

    /** @return array<string,array{string}> */
    public static function nonDeveloperModes(): array
    {
        return ['default' => [State::MODE_DEFAULT], 'production' => [State::MODE_PRODUCTION]];
    }

    #[DataProvider('nonDeveloperModes')]
    public function testItIgnoresAllowHttpAndAllowCidrsUnlessTheModeIsDeveloper(string $mode): void
    {
        $this->mode = $mode;
        $this->set('allow_http', true);
        $this->set('allow_cidrs', ['10.0.0.0/8']);
        $this->set('ports', [80]);
        $this->records = ['10.1.2.3'];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->never())->method('critical');
        $policy = $this->policy($logger);
        $this->assertFalse($policy->policy()->allowHttp);
        $this->assertSame([], $policy->policy()->allowCidrs);
        $this->assertSame([80], $policy->policy()->ports);
        $this->rejects('http://hooks.example.com/in', 'scheme', $policy);
    }

    public function testItHonoursAllowHttpAndAllowCidrsInDeveloperMode(): void
    {
        $this->mode = State::MODE_DEVELOPER;
        $this->set('allow_http', true);
        $this->set('allow_cidrs', ['10.0.0.0/8']);
        $policy = $this->policy();
        $this->assertTrue($policy->policy()->allowHttp);
        $this->assertSame(['10.0.0.0/8'], $policy->policy()->allowCidrs);
    }

    public function testItLogsACriticalMessageWhenDevFlagsAreActive(): void
    {
        $this->set('allow_http', true);
        $this->set('allow_cidrs', ['10.0.0.0/8']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical')->with(
            $this->logicalAnd(
                $this->stringContains('allow_http'),
                $this->stringContains('allow_cidrs'),
                $this->logicalNot($this->stringContains('10.0.0.0'))
            )
        );
        $policy = $this->policy($logger);
        $policy->policy();
        $policy->policy();
    }

    public function testItShowsOneMessageForAnUnresolvableHostAndAHostThatResolvesToAPrivateAddress(): void
    {
        $messages = [];
        foreach ([[], ['10.1.2.3']] as $records) {
            $this->records = $records;
            try {
                $this->policy()->validateForSave('https://hooks.example.com/in');
                $this->fail('accepted');
            } catch (UnsafeUrlException $e) {
                $messages[] = $e->getMessage();
            }
        }
        $this->assertSame($messages[0], $messages[1]);
    }
}
