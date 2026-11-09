<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Http\ClientIp;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use Furan\FormBuilder\Api\SubmissionInput;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private MemoryCounter $counter;

    protected function setUp(): void
    {
        $this->counter = new MemoryCounter(fn (): int => 6000);
    }

    private function limiter(string $bucket, int $limit = 2, int $formLimit = 0): RateLimiter
    {
        $ip = $this->createStub(ClientIp::class);
        $ip->method('bucket')->willReturn($bucket);
        $ip->method('bucketOf')->willReturnCallback(static fn (string $address): string => $address === '' ? 'unknown' : $bucket);
        $config = $this->createStub(Config::class);
        $config->method('getRateLimit')->willReturn($limit);
        $config->method('getFormRateLimit')->willReturn($formLimit);
        $config->method('getRateWindowMinutes')->willReturn(10);
        return new RateLimiter($this->counter, $ip, $config, fn (): int => 6000);
    }

    private function form(int $formId = 3): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn($formId);
        return $form;
    }

    private function attempt(RateLimiter $limiter, int $formId = 3): ?string
    {
        $result = $limiter->check(new SubmissionInput(clientIp: '9.9.9.9'), $this->form($formId));
        return $result->isPass() ? null : $result->getCode();
    }

    public function testItAllowsSubmissionsUpToTheRateLimitAndRejectsTheNext(): void
    {
        $limiter = $this->limiter('1.2.3.4');
        $this->assertNull($this->attempt($limiter));
        $this->assertNull($this->attempt($limiter));
        $this->assertSame('rate_limit', $this->attempt($limiter));
        $this->assertNull($this->attempt($limiter, 4));
    }

    public function testItKeysTheRateLimitByTheClientBucketAndWindow(): void
    {
        $this->attempt($this->limiter('1.2.3.4'));

        $this->assertSame(['ffb_rl_1.2.3.4|3|10'], array_keys($this->counter->rows));
        $this->assertSame(7200, $this->counter->rows['ffb_rl_1.2.3.4|3|10'][1]);
    }

    public function testItBucketsTheClientIpOfTheSubmissionInput(): void
    {
        $ip = $this->createMock(ClientIp::class);
        $ip->expects($this->once())->method('bucketOf')->with('203.0.113.9')->willReturn('203.0.113.9');
        $ip->expects($this->never())->method('bucket');
        $config = $this->createStub(Config::class);
        $config->method('getRateLimit')->willReturn(5);
        $config->method('getRateWindowMinutes')->willReturn(10);
        $limiter = new RateLimiter($this->counter, $ip, $config, fn (): int => 6000);

        $limiter->check(new SubmissionInput(clientIp: '203.0.113.9'), $this->form());

        $this->assertSame(['ffb_rl_203.0.113.9|3|10'], array_keys($this->counter->rows));
    }

    public function testItSharesOneLimitAcrossAnIpv6Slash64(): void
    {
        $this->assertNull($this->attempt($this->limiter('2001:db8::/64', 1)));
        $this->assertSame('rate_limit', $this->attempt($this->limiter('2001:db8::/64', 1)));
    }

    public function testItNeverBlocksWhenThePerVisitorRateLimitIsZero(): void
    {
        $limiter = $this->limiter('1.2.3.4', 0);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($this->attempt($limiter));
        }
    }

    public function testThePerVisitorCheckNoLongerTouchesTheFormCeiling(): void
    {
        $limiter = $this->limiter('1.1.1.1', 10, 1);
        $this->assertNull($this->attempt($limiter));
        $this->assertNull($this->attempt($limiter));
        $this->assertFalse($limiter->formCeilingReached($this->form()));
    }

    public function testItBlocksAFormAfterTheGlobalPerFormCeilingRegardlessOfIp(): void
    {
        $this->assertFalse($this->limiter('1.1.1.1', 0, 2)->formCeilingReached($this->form()));
        $this->assertFalse($this->limiter('2.2.2.2', 0, 2)->formCeilingReached($this->form()));
        $this->assertTrue($this->limiter('3.3.3.3', 0, 2)->formCeilingReached($this->form()));
        $this->assertFalse($this->limiter('3.3.3.3', 0, 2)->formCeilingReached($this->form(4)));
        $this->assertFalse($this->limiter('3.3.3.3', 0, 0)->formCeilingReached($this->form()), '0 disables it');
    }

    public function testItOffersAPerClientLimitForOtherEndpoints(): void
    {
        $limiter = $this->limiter('1.2.3.4');
        $this->assertTrue($limiter->allow('map', 2, 600));
        $this->assertTrue($limiter->allow('map', 2, 600));
        $this->assertFalse($limiter->allow('map', 2, 600));
        $this->assertTrue($limiter->allow('other', 2, 600));
    }

    public function testItOffersAKeyedLimitForCallersOutsideTheSubmitPath(): void
    {
        $limiter = $this->limiter('1.2.3.4');
        $this->assertTrue($limiter->hit('thread_reply_9', 2, 3600));
        $this->assertTrue($limiter->hit('thread_reply_9', 2, 3600));
        $this->assertFalse($limiter->hit('thread_reply_9', 2, 3600));
        $this->assertTrue($limiter->hit('thread_reply_10', 2, 3600));
        $this->assertArrayHasKey('ffb_key_thread_reply_9|1', $this->counter->rows);
    }

    public function testABurstStraddlingAWindowBoundaryCannotDoubleTheLimit(): void
    {
        $now = 5999;
        $counter = new MemoryCounter(function () use (&$now): int {
            return $now;
        });
        $limiter = new RateLimiter($counter, $this->createStub(ClientIp::class), $this->createStub(Config::class), function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue($limiter->hit('burst', 30, 600));
        }
        // One second into the next window almost all of the previous window still counts.
        $now = 6001;
        $this->assertFalse($limiter->hit('burst', 30, 600));
        // Half way through, half of it has slid out.
        $now = 6300;
        $allowed = 0;
        while ($limiter->hit('burst', 30, 600)) {
            $allowed++;
        }
        $this->assertSame(14, $allowed);
    }
}
