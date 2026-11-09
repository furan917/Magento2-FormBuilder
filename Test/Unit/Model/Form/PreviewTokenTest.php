<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Form;

use Furan\FormBuilder\Model\Form\PreviewToken;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class PreviewTokenTest extends TestCase
{
    private int $now = 1800000000;

    private function tokens(string $key = 'k0 k1'): PreviewToken
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn($key);

        return new PreviewToken(new SigningKeys($config), fn (): int => $this->now);
    }

    public function testItAcceptsATokenForTheFormItWasIssuedFor(): void
    {
        $token = $this->tokens()->issue(7);

        $this->assertMatchesRegularExpression('/^1800007200\.[0-9a-f]{64}$/D', $token);
        $this->assertTrue($this->tokens()->verify($token, 7));
    }

    public function testItRefusesATokenForAnotherForm(): void
    {
        $this->assertFalse($this->tokens()->verify($this->tokens()->issue(7), 8));
    }

    public function testItRefusesATamperedToken(): void
    {
        $tokens = $this->tokens();
        [$expires, $mac] = explode('.', $tokens->issue(7));

        $this->assertFalse($tokens->verify(($expires + 3600) . '.' . $mac, 7), 'extended expiry');
        $this->assertFalse($tokens->verify($expires . '.' . strrev($mac), 7), 'bad signature');
        $this->assertFalse($tokens->verify($expires . '.' . $mac . "\n", 7), 'trailing newline');
        $this->assertFalse($tokens->verify('', 7), 'empty');
        $this->assertFalse($tokens->verify('abc.' . $mac, 7), 'not a number');
        $this->assertFalse($this->tokens('other')->verify($expires . '.' . $mac, 7), 'other key');
    }

    public function testItRefusesAnExpiredToken(): void
    {
        $tokens = $this->tokens();
        $token = $tokens->issue(7);

        $this->now += 7200;
        $this->assertTrue($tokens->verify($token, 7));
        $this->now += 1;
        $this->assertFalse($tokens->verify($token, 7));
    }

    public function testItStillAcceptsATokenSignedBeforeTheKeyWasRotated(): void
    {
        $token = $this->tokens('k0')->issue(7);

        $this->assertTrue($this->tokens('k0 k1')->verify($token, 7));
    }

    public function testItRefusesToIssueWithoutACryptKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->tokens('')->issue(7);
    }
}
