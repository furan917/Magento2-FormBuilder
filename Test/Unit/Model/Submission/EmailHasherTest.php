<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\EmailHasher;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class EmailHasherTest extends TestCase
{
    private function hasher(?string $keys): EmailHasher
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->with('crypt/key')->willReturn($keys);

        return new EmailHasher($config);
    }

    private function fingerprint(string $email, string $key): string
    {
        $sub = hash_hmac('sha256', 'furan_formbuilder_email', $key, true);

        return hash_hmac('sha256', $email, $sub, true);
    }

    public function testItFingerprintsTheLowerCasedTrimmedEmailWithAnHmacKeyedByASubKeyOfTheNewestCryptKey(): void
    {
        $result = $this->hasher("old\nnew")->hash("  Ann@Example.COM ");

        $this->assertSame(
            ['hash' => $this->fingerprint('ann@example.com', 'new'), 'version' => 1],
            $result
        );
        $this->assertSame(32, strlen($result['hash']));
        $this->assertNull($this->hasher('k')->hash('   '));
    }

    public function testItReturnsOneCandidateFingerprintPerAvailableCryptKeyVersion(): void
    {
        $candidates = $this->hasher("old  new")->candidates(' A@B.co ');

        $this->assertSame(
            [0 => $this->fingerprint('a@b.co', 'old'), 1 => $this->fingerprint('a@b.co', 'new')],
            $candidates
        );
        $this->assertSame([], $this->hasher('old')->candidates(' '));
    }
}
