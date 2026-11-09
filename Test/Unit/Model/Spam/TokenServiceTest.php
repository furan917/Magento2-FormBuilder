<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Spam;

use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Model\Spam\TokenService;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase
{
    private int $now = 1000000;
    private MemoryCounter $counter;

    private function derived(string $key): string
    {
        return hash_hmac('sha256', 'furan_formbuilder_token', $key);
    }

    private function service(string $key = "oldkey\nnewkey"): TokenService
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(
            static fn (string $path) => $path === 'crypt/key' ? $key : '2024-01-01'
        );
        $this->counter ??= new MemoryCounter(fn (): int => $this->now);
        return new TokenService(new SigningKeys($config), $this->counter, fn (): int => $this->now);
    }

    public function testItIssuesATokenThatVerifiesForTheSameForm(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 10;
        $this->assertSame(
            hash_hmac('sha256', '7|1000000|' . explode('.', $token)[1], $this->derived('newkey')),
            explode('.', $token)[2]
        );
        $this->assertTrue($service->verify($token, 7, 3));
    }

    public function testItReportsTheAgeOfAValidlySignedTokenOfThatForm(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->assertSame(0, $service->age($token, 7));
        $this->now += 2;
        $this->assertSame(2, $service->age($token, 7));
        $this->assertSame(2, $this->service('oldkey')->age($this->tokenFor('oldkey', 7, $this->now - 2), 7));
    }

    public function testItReportsNoAgeForForgedForeignOrOutOfRangeTokens(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 2;
        $this->assertNull($service->age($token, 8));
        $this->assertNull($service->age(substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a'), 7));
        $this->assertNull($service->age($this->tokenFor('otherkey', 7, $this->now - 2), 7));
        $this->assertNull($service->age($this->tokenFor('newkey', 7, $this->now + 100), 7));
        foreach (['', 'abc', '1.2.3', '1000000.' . str_repeat('a', 64)] as $bad) {
            $this->assertNull($service->age($bad, 7), $bad);
        }
        $this->now += 21600;
        $this->assertNull($service->age($token, 7));
    }

    private function tokenFor(string $key, int $formId, int $timestamp): string
    {
        $nonce = '0123456789abcdef';
        return $timestamp . '.' . $nonce . '.'
            . hash_hmac('sha256', $formId . '|' . $timestamp . '|' . $nonce, $this->derived($key));
    }

    public function testItRejectsATokenForAnotherForm(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 10;
        $this->assertFalse($service->verify($token, 8, 3));
    }

    public function testItRejectsTokensYoungerThanTheMinimumSeconds(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 2;
        $this->assertFalse($service->verify($token, 7, 3));
        $this->now += 1;
        $this->assertTrue($service->verify($token, 7, 3));
    }

    public function testItRejectsExpiredFutureAndMalformedTokens(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 21601;
        $this->assertFalse($service->verify($token, 7, 3));

        $this->now = 1000000;
        $future = $this->now + 100;
        $nonce = '0123456789abcdef';
        $futureToken = $future . '.' . $nonce . '.'
            . hash_hmac('sha256', '7|' . $future . '|' . $nonce, $this->derived('newkey'));
        $this->assertFalse($service->verify($futureToken, 7, 3));

        foreach (['', 'abc', '123', 'x.y', '1000000.', '.abc', '1.2.3', '-5.abc', '1000000.' . str_repeat('a', 64)] as $bad) {
            $this->assertFalse($service->verify($bad, 7, 3), $bad);
        }
    }

    public function testItRefusesToIssueTokensWithoutACryptKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service('')->issue(7);
    }

    public function testItVerifiesTokensSignedWithAnOlderCryptKeyAfterRotation(): void
    {
        $token = $this->service('oldkey')->issue(7);
        $this->now += 10;

        $this->assertTrue($this->service("oldkey\nnewkey")->verify($token, 7, 3));
        $this->assertFalse($this->service('otherkey')->verify($token, 7, 3));
        $this->assertFalse($this->service('')->verify($token, 7, 3));
    }

    public function testItDerivesTheTokenHmacKeyForItsPurpose(): void
    {
        $token = $this->service("rawkey")->issue(7);
        [, $nonce, $hmac] = explode('.', $token);
        $this->assertSame(hash_hmac('sha256', '7|1000000|' . $nonce, $this->derived('rawkey')), $hmac);
        $this->assertNotSame(hash_hmac('sha256', '7|1000000|' . $nonce, 'rawkey'), $hmac);
    }

    public function testItRejectsATokenThatWasConsumedByASuccessfulSubmission(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 10;
        $this->assertTrue($service->verify($token, 7, 3));
        $this->assertTrue($service->consume($token));
        $this->assertFalse($service->consume($token));
        $this->assertFalse($service->verify($token, 7, 3));
        $key = 'ffb_tok_' . hash('sha256', $token);
        $this->assertSame(1021610, $this->counter->rows[$key][1], "kept for the token lifetime");
    }

    public function testItIssuesDistinctTokensWithinTheSameSecond(): void
    {
        $service = $this->service();
        $first = $service->issue(7);
        $second = $service->issue(7);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^1000000\.[0-9a-f]{16}\.[0-9a-f]{64}$/D', $first);
        $this->now += 10;
        $this->assertTrue($service->verify($first, 7, 3));
        $this->assertTrue($service->verify($second, 7, 3));
        $service->consume($first);
        $this->assertTrue($service->verify($second, 7, 3));
    }

    public function testItReleasesAConsumedToken(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 10;
        $service->consume($token);
        $this->assertFalse($service->verify($token, 7, 3));
        $service->release($token);
        $this->assertTrue($service->verify($token, 7, 3));
    }

    public function testItLimitsHowOftenOneTokenCanBeRetriedAfterFailedSubmits(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 10;
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($service->consume($token), "attempt $i");
            $service->release($token);
        }
        $this->assertFalse($service->verify($token, 7, 3));
        $this->assertFalse($service->consume($token));
    }

    public function testOnlyOneConcurrentRequestCanHoldAToken(): void
    {
        $service = $this->service();
        $token = $service->issue(7);
        $this->now += 10;
        $this->assertTrue($service->consume($token));
        $this->assertFalse($service->consume($token), 'second request while the first is processing');
    }
}
