<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Http\ClientIp;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Furan\FormBuilder\Model\Thread\ThreadLimits;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use PHPUnit\Framework\TestCase;

class ThreadLimitsTest extends TestCase
{
    public function testItChecksTokensAgainstThePerClientRateLimiter(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects($this->exactly(2))->method('allow')->with('thread_token', 30, 600)
            ->willReturnOnConsecutiveCalls(true, false);

        $limits = new ThreadLimits($rateLimiter);
        $this->assertTrue($limits->allowTokenAttempt());
        $this->assertFalse($limits->allowTokenAttempt());
    }

    public function testItAllowsTenGuestRepliesPerSubmissionPerHourWithAtomicCounters(): void
    {
        $clock = static fn (): int => 1_800_000_000;
        $limiter = new RateLimiter(new MemoryCounter($clock), $this->createStub(ClientIp::class), $this->createStub(Config::class), $clock);
        $limits = new ThreadLimits($limiter);
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limits->allowGuestReply(42));
        }
        $this->assertFalse($limits->allowGuestReply(42));
        $this->assertTrue($limits->allowGuestReply(43));
    }

    public function testItAllowsTenCustomerRepliesPerCustomerPerHour(): void
    {
        $clock = static fn (): int => 1_800_000_000;
        $limiter = new RateLimiter(new MemoryCounter($clock), $this->createStub(ClientIp::class), $this->createStub(Config::class), $clock);
        $limits = new ThreadLimits($limiter);
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limits->allowCustomerReply(5));
        }
        $this->assertFalse($limits->allowCustomerReply(5));
        $this->assertTrue($limits->allowCustomerReply(6));
        $this->assertTrue($limits->allowGuestReply(5), 'a guest counter is separate');
    }
}
