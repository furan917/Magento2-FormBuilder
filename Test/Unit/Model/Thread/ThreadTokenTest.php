<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Thread\ThreadToken;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class ThreadTokenTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const FP = "\x01\x02fingerprint-of-the-recipient!!!";
    private const OTHER_FP = "\x09\x09fingerprint-of-someone-else!!!!";

    private int $now = self::NOW;

    private function token(string $keys = 'oldkey newkey'): ThreadToken
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn (string $path) => $path === 'crypt/key' ? $keys : null);
        return new ThreadToken($config, fn (): int => $this->now);
    }

    public function testItIssuesATokenThatVerifiesForTheSameSubmissionRecipientAndVersion(): void
    {
        $token = $this->token()->issue(42, self::FP, 3);

        $this->assertMatchesRegularExpression('/^\d+\.42\.[0-9a-f]{64}$/D', (string)$token);
        $parsed = $this->token()->parse((string)$token);
        $this->assertSame(42, $parsed['id']);
        $this->assertTrue($this->token()->matches($parsed, [self::FP], 3));
    }

    public function testItRejectsAnotherRecipientFingerprint(): void
    {
        $parsed = $this->token()->parse((string)$this->token()->issue(42, self::FP, 3));
        $this->assertFalse($this->token()->matches($parsed, [self::OTHER_FP], 3));
    }

    public function testItRejectsATokenAfterTheRevocationCounterMoved(): void
    {
        $parsed = $this->token()->parse((string)$this->token()->issue(42, self::FP, 3));
        $this->assertFalse($this->token()->matches($parsed, [self::FP], 4));
    }

    public function testItRejectsATamperedSubmissionIdOrSignature(): void
    {
        $token = (string)$this->token()->issue(42, self::FP, 0);
        [$expires, , $mac] = explode('.', $token);

        $moved = $this->token()->parse($expires . '.43.' . $mac);
        $this->assertFalse($this->token()->matches($moved, [self::FP], 0));
        $flipped = $this->token()->parse($expires . '.42.' . strrev($mac));
        $this->assertFalse($this->token()->matches($flipped, [self::FP], 0));
        $later = $this->token()->parse(($expires + 100) . '.42.' . $mac);
        $this->assertFalse($this->token()->matches($later, [self::FP], 0));
    }

    public function testItExpiresAfterThirtyDays(): void
    {
        $token = (string)$this->token()->issue(42, self::FP, 0);
        $this->now = self::NOW + 30 * 86400;
        $this->assertNotNull($this->token()->parse($token));
        $this->now = self::NOW + 30 * 86400 + 1;
        $this->assertNull($this->token()->parse($token));
    }

    public function testItRejectsExpiriesFurtherAheadThanTheLifetime(): void
    {
        $this->now = self::NOW + 86400;
        $future = (string)$this->token()->issue(42, self::FP, 0);
        $this->now = self::NOW;
        $this->assertNull($this->token()->parse($future));
    }

    public function testItRejectsMalformedTokens(): void
    {
        foreach (['', 'x', '1.2', '1.2.' . str_repeat('g', 64), '1.2.' . str_repeat('a', 63),
            " 1800000000.1." . str_repeat('a', 64), "1800000000.1." . str_repeat('a', 64) . "\n",
            '1800000000.0.' . str_repeat('a', 64), '1800000000.-1.' . str_repeat('a', 64),
            '01800000000.1.' . str_repeat('a', 64), '0.1.' . str_repeat('a', 64)] as $bad) {
            $this->assertNull($this->token()->parse($bad), var_export($bad, true));
        }
    }

    public function testItSignsWithTheNewestKeyAndStillAcceptsOlderKeys(): void
    {
        $old = (string)$this->token('oldkey')->issue(42, self::FP, 0);
        $new = (string)$this->token('oldkey newkey')->issue(42, self::FP, 0);

        $this->assertNotSame($old, $new);
        $rotated = $this->token('oldkey newkey');
        $this->assertTrue($rotated->matches($rotated->parse($old), [self::FP], 0));
        $this->assertTrue($rotated->matches($rotated->parse($new), [self::FP], 0));
        $this->assertFalse($this->token('oldkey')->matches($this->token('oldkey')->parse($new), [self::FP], 0));
    }

    public function testItRefusesToIssueOrVerifyWithoutACryptKey(): void
    {
        $this->assertNull($this->token('  ')->issue(42, self::FP, 0));
        $valid = $this->token()->issue(42, self::FP, 0);
        $keyless = $this->token('');
        $parsed = $keyless->parse((string)$valid);
        $this->assertFalse($parsed !== null && $keyless->matches($parsed, [self::FP], 0));
    }

    public function testItUsesItsOwnKeyLabelSoClaimSignaturesNeverMatch(): void
    {
        $token = (string)$this->token('k')->issue(42, self::FP, 0);
        [$expires, , $mac] = explode('.', $token);
        $claimKey = hash_hmac('sha256', 'furan_formbuilder_claim', 'k');
        $this->assertNotSame(hash_hmac('sha256', '42|' . bin2hex(self::FP) . '|0|' . $expires, $claimKey), $mac);
    }
}
