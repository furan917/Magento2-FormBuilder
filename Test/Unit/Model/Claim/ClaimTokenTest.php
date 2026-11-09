<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Claim;

use Furan\FormBuilder\Model\Claim\ClaimToken;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class ClaimTokenTest extends TestCase
{
    private int $now = 1800000000;

    private function token(string $key = 'k0 k1'): ClaimToken
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->with('crypt/key')->willReturn($key);

        return new ClaimToken(new SigningKeys($config), fn(): int => $this->now);
    }

    public function testItRejectsAnExpiredOrTamperedClaimToken(): void
    {
        $claim = $this->token();
        $hash = hash('sha256', 'a', true);
        $token = $claim->issue(8, $hash);
        [$ts, $id, $mac] = explode('.', $token);

        $this->assertMatchesRegularExpression('/^1800000000\.8\.[0-9a-f]{64}$/D', $token);
        $this->assertTrue($claim->verify($token, 8, [$hash]));
        $this->assertTrue($claim->verify($token, 8, [hash('sha256', 'z', true), $hash]), 'any candidate');
        $this->assertFalse($claim->verify($token, 9, [$hash]), 'other customer');
        $this->assertFalse($claim->verify($token, 8, [hash('sha256', 'b', true)]), 'other email');
        $this->assertFalse($claim->verify($token, 8, []), 'no candidates');
        $this->assertFalse($claim->verify($ts . '.9.' . $mac, 9, [$hash]), 'rewritten customer');
        $this->assertFalse($claim->verify($ts . '.' . $id . '.' . strrev($mac), 8, [$hash]), 'bad signature');
        $this->assertFalse($claim->verify($token . "\n", 8, [$hash]), 'trailing newline');
        $this->assertFalse($claim->verify('', 8, [$hash]), 'empty');
        $this->assertFalse($this->token('other')->verify($token, 8, [$hash]), 'other key');

        $this->now += 7 * 86400;
        $this->assertTrue($claim->verify($token, 8, [$hash]), 'valid at the TTL');
        $this->now += 1;
        $this->assertFalse($claim->verify($token, 8, [$hash]), 'expired');

        $this->now = 1800000000;
        $future = $claim->issue(8, $hash);
        $this->now -= 61;
        $this->assertFalse($claim->verify($future, 8, [$hash]), 'future dated');
    }

    public function testItKeepsClaimLinksWorkingAfterACryptKeyRotationAndRefusesToSignWithoutAKey(): void
    {
        $hash = hash('sha256', 'a', true);
        $token = $this->token('k0')->issue(8, $hash);

        $this->assertTrue($this->token('k0 k1')->verify($token, 8, [$hash]));
        $this->assertFalse($this->token('')->verify($token, 8, [$hash]));
        $this->expectException(\RuntimeException::class);
        $this->token('')->issue(8, $hash);
    }
}
