<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\SecretUnavailableException;
use Furan\FormBuilder\Model\Webhook\Signer;
use Furan\FormBuilder\Test\Unit\Helper\SignatureVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SignerTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const OTHER = 'whsec_C2FVsBQIhrscChlQIMV+b5sSYspob7oD';

    public function testItReproducesTheStandardWebhooksTestVector(): void
    {
        $headers = $this->signer()->headers(
            'msg_p5jXN8AQM9LWM0D4loKWxJek',
            1614265330,
            '{"test": 2432232314}',
            self::SECRET,
            null,
            null,
            1614265330
        );

        $this->assertSame([
            'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
            'webhook-timestamp' => '1614265330',
            'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
        ], $headers);
    }

    public function testItSignsWithBothSecretsDuringARotationWindowAndOnlyTheCurrentAfterIt(): void
    {
        $signer = $this->signer();
        $now = 1700000000;
        $future = gmdate('Y-m-d H:i:s', $now + 60);
        $past = gmdate('Y-m-d H:i:s', $now - 1);

        $both = $signer->headers('msg_1', $now, 'body', self::SECRET, self::OTHER, $future, $now);
        $parts = explode(' ', $both['webhook-signature']);
        $this->assertCount(2, $parts);
        $this->assertSame($signer->headers('msg_1', $now, 'body', self::SECRET, null, null, $now)['webhook-signature'], $parts[0]);
        $this->assertSame($signer->headers('msg_1', $now, 'body', self::OTHER, null, null, $now)['webhook-signature'], $parts[1]);

        $expired = $signer->headers('msg_1', $now, 'body', self::SECRET, self::OTHER, $past, $now);
        $this->assertCount(1, explode(' ', $expired['webhook-signature']));
        $none = $signer->headers('msg_1', $now, 'body', self::SECRET, self::OTHER, null, $now);
        $this->assertCount(1, explode(' ', $none['webhook-signature']));
    }

    #[DataProvider('badSecrets')]
    public function testItRefusesToSignWithAnEmptyOrMalformedSecret(string $secret): void
    {
        $this->expectException(SecretUnavailableException::class);
        $this->signer()->headers('msg_1', 1700000000, 'body', $secret, null, null, 1700000000);
    }

    /** @return array<string, array{string}> */
    public static function badSecrets(): array
    {
        return [
            'empty' => [''],
            'no prefix' => ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'],
            'prefix only' => ['whsec_'],
            'not base64' => ['whsec_!!!!notbase64!!!!notbase64!!!!'],
            'too short' => ['whsec_' . 'AAAAAAAAAAAAAAAAAAAAAAAA'],
            'padding junk' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw=x'],
        ];
    }

    public function testItSignsWithTheCurrentOnlyAndWarnsWhenThePreviousSecretIsUnusable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->callback(static fn(string $m): bool => !str_contains($m, 'whsec')),
            $this->equalTo(['endpoint_id' => 7])
        );
        $now = 1700000000;
        $headers = (new Signer($logger))->headers(
            'msg_1',
            $now,
            'body',
            self::SECRET,
            '',
            gmdate('Y-m-d H:i:s', $now + 60),
            $now,
            7
        );
        $this->assertCount(1, explode(' ', $headers['webhook-signature']));
    }

    public function testItKeepsTheWebhookIdAndRefreshesTheTimestampOnRetry(): void
    {
        $signer = $this->signer();
        $first = $signer->headers('msg_1', 1700000000, 'body', self::SECRET, null, null, 1700000000);
        $retry = $signer->headers('msg_1', 1700000900, 'body', self::SECRET, null, null, 1700000900);

        $this->assertSame($first['webhook-id'], $retry['webhook-id']);
        $this->assertSame('1700000900', $retry['webhook-timestamp']);
        $this->assertNotSame($first['webhook-signature'], $retry['webhook-signature']);
    }

    public function testVerifierAcceptsEitherSignatureWithinToleranceOnly(): void
    {
        $now = 1700000000;
        $h = $this->signer()->headers('msg_1', $now, 'body', self::SECRET, self::OTHER, gmdate('Y-m-d H:i:s', $now + 60), $now);
        $v = new SignatureVerifier();

        $this->assertTrue($v->verify(self::SECRET, 'body', $h['webhook-id'], $h['webhook-timestamp'], $h['webhook-signature'], $now + 300));
        $this->assertTrue($v->verify(self::OTHER, 'body', $h['webhook-id'], $h['webhook-timestamp'], $h['webhook-signature'], $now));
        $this->assertFalse($v->verify(self::SECRET, 'body', $h['webhook-id'], $h['webhook-timestamp'], $h['webhook-signature'], $now + 301));
        $this->assertFalse($v->verify(self::SECRET, 'bodY', $h['webhook-id'], $h['webhook-timestamp'], $h['webhook-signature'], $now));
        $this->assertFalse($v->verify(self::SECRET, 'body', $h['webhook-id'], 'abc', $h['webhook-signature'], $now));
        $this->assertFalse($v->verify('', 'body', $h['webhook-id'], $h['webhook-timestamp'], $h['webhook-signature'], $now));
    }

    private function signer(): Signer
    {
        return new Signer($this->createStub(LoggerInterface::class));
    }
}
