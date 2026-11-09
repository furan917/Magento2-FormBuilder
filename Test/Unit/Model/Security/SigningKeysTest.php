<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Security;

use Furan\FormBuilder\Model\Security\SigningKeys;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class SigningKeysTest extends TestCase
{
    private function keys(?string $crypt): SigningKeys
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnMap([['crypt/key', null, $crypt], ['install/date', null, 'Mon, 1 Jan 2024']]);

        return new SigningKeys($config);
    }

    public function testItDerivesOneKeyPerCryptKeyNewestFirst(): void
    {
        $keys = $this->keys("old\nnew")->all('label');

        $this->assertSame([hash_hmac('sha256', 'label', 'new'), hash_hmac('sha256', 'label', 'old')], $keys);
        $this->assertSame($keys[0], $this->keys("old\nnew")->newest('label'));
    }

    public function testItSeparatesLabels(): void
    {
        $this->assertNotSame($this->keys('k')->newest('a'), $this->keys('k')->newest('b'));
    }

    public function testItRefusesToSignWithoutACryptKeyInsteadOfFallingBackToAWeakKey(): void
    {
        $this->assertSame([], $this->keys('  ')->all('label'));
        $this->expectException(\RuntimeException::class);
        $this->keys(null)->newest('label');
    }
}
