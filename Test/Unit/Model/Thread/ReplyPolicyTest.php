<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\ReplyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReplyPolicyTest extends TestCase
{
    private function policy(bool $replies = true, bool $guestLinks = true, int $max = 200, int $count = 0): ReplyPolicy
    {
        $config = $this->createStub(Config::class);
        $config->method('isCustomerRepliesEnabled')->willReturn($replies);
        $config->method('isGuestReplyLinksEnabled')->willReturn($guestLinks);
        $config->method('getMaxThreadMessages')->willReturn($max);
        $messages = $this->createStub(Messages::class);
        $messages->method('countUpTo')->willReturn($count);

        return new ReplyPolicy($config, $messages);
    }

    #[DataProvider('resolution')]
    public function testItResolvesTheFormSettingOverTheStoreConfig(array $settings, bool $store, bool $expected): void
    {
        $this->assertSame($expected, $this->policy($store)->customerRepliesOpen($settings, 1));
    }

    public static function resolution(): array
    {
        return [
            'missing follows store on' => [[], true, true],
            'missing follows store off' => [[], false, false],
            'default follows store' => [['customer_replies' => 'default'], false, false],
            'on overrides store off' => [['customer_replies' => 'on'], false, true],
            'off overrides store on' => [['customer_replies' => 'off'], true, false],
            'unknown follows store' => [['customer_replies' => 'maybe'], true, true],
        ];
    }

    public function testItOpensGuestRepliesOnlyWithRepliesAndGuestLinksOn(): void
    {
        $this->assertTrue($this->policy(true, true)->guestRepliesOpen([], 1));
        $this->assertFalse($this->policy(true, false)->guestRepliesOpen([], 1));
        $this->assertFalse($this->policy(false, true)->guestRepliesOpen([], 1));
        $this->assertTrue($this->policy(false, true)->guestRepliesOpen(['customer_replies' => 'on'], 1));
        $this->assertFalse($this->policy(true, true)->guestRepliesOpen(['customer_replies' => 'off'], 1));
    }

    public function testItTreatsAThreadAtTheLimitAsFull(): void
    {
        $this->assertFalse($this->policy(max: 200, count: 199)->isFull(9));
        $this->assertTrue($this->policy(max: 200, count: 200)->isFull(9));
        $this->assertTrue($this->policy(max: 200, count: 201)->isFull(9));
    }

    public function testItNeverCountsWhenTheLimitIsZero(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getMaxThreadMessages')->willReturn(0);
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->never())->method('countUpTo');

        $this->assertFalse((new ReplyPolicy($config, $messages))->isFull(9));
    }
}
