<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Model\Spam\TokenService;
use Furan\FormBuilder\Model\Submission\EditToken;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class EditTokenTest extends TestCase
{
    private int $now = 1800000000;

    private function token(string $key = 'k0 k1'): EditToken
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->with('crypt/key')->willReturn($key);

        return new EditToken(new SigningKeys($config), fn(): int => $this->now);
    }

    public function testItSignsAnEditTokenBoundToSubmissionCustomerAndForm(): void
    {
        $token = $this->token()->issue(55, 8, 3);

        $this->assertMatchesRegularExpression('/^1800000000\.55\.[0-9a-f]{64}$/D', $token);
        $this->assertSame(55, $this->token()->verify($token, 8, 3));
        $this->assertSame($token, $this->token()->issue(55, 8, 3));
        $this->assertNotSame($token, $this->token()->issue(55, 8, 4));
        $this->assertNotSame($token, $this->token()->issue(56, 8, 3));
    }

    public function testItRejectsAnEditTokenThatIsExpiredTamperedOrIssuedToAnotherCustomer(): void
    {
        $edit = $this->token();
        $token = $edit->issue(55, 8, 3);
        [$ts, $id, $mac] = explode('.', $token);

        $this->assertNull($edit->verify($token, 9, 3), 'other customer');
        $this->assertNull($edit->verify($token, 8, 4), 'other form');
        $this->assertNull($edit->verify($ts . '.56.' . $mac, 8, 3), 'other submission');
        $this->assertNull($edit->verify($ts . '.' . $id . '.' . strrev($mac), 8, 3), 'bad signature');
        $this->assertNull($edit->verify($token . '0', 8, 3), 'trailing junk');
        $this->assertNull($edit->verify($token . "\n", 8, 3), 'trailing newline');
        $this->assertNull($edit->verify('', 8, 3), 'empty');
        $this->assertNull($this->token('other')->verify($token, 8, 3), 'other key');

        $this->now += 3600;
        $this->assertSame(55, $edit->verify($token, 8, 3), 'still valid at the TTL');
        $this->now += 1;
        $this->assertNull($edit->verify($token, 8, 3), 'expired');

        $this->now = 1800000000;
        $future = $edit->issue(55, 8, 3);
        $this->now -= 60;
        $this->assertSame(55, $edit->verify($future, 8, 3), 'small clock skew allowed');
        $this->now -= 1;
        $this->assertNull($edit->verify($future, 8, 3), 'future dated beyond the skew');
    }

    public function testItCannotReplayAFormTokenAsAnEditToken(): void
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->with('crypt/key')->willReturn('k0 k1');
        $formToken = (new TokenService(new SigningKeys($config), new \Furan\FormBuilder\Test\Unit\Helper\MemoryCounter(), fn(): int => $this->now))->issue(3);
        [$ts, , $mac] = explode('.', $formToken);

        $this->assertNull($this->token()->verify($ts . '.55.' . $mac, 8, 3));
    }

    public function testItKeepsEditLinksWorkingAfterACryptKeyRotationAndRefusesToSignWithoutAKey(): void
    {
        $token = $this->token('k0')->issue(55, 8, 3);

        $this->assertSame(55, $this->token('k0 k1')->verify($token, 8, 3));
        $this->assertNotSame($token, $this->token('k0 k1')->issue(55, 8, 3), 'signed with the newest key');
        $this->assertNull($this->token('')->verify($token, 8, 3));
        $this->expectException(\RuntimeException::class);
        $this->token('')->issue(55, 8, 3);
    }
}
