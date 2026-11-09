<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Condition;

use Furan\FormBuilder\Model\Condition\Today;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

class TodayTest extends TestCase
{
    private function today(mixed $timezone, int $expectedStore = 2): Today
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')
            ->with('general/locale/timezone', ScopeInterface::SCOPE_STORE, $expectedStore)
            ->willReturn($timezone);
        return new Today($config);
    }

    public function testItResolvesTodayForAGivenUtcInstantInTheStoreTimezone(): void
    {
        $instant = '2026-11-07 23:30:00';
        $this->assertSame('2026-11-08', $this->today('Europe/Berlin')->forStore(2, $instant));
        $this->assertSame('2026-11-07', $this->today('America/New_York')->forStore(2, $instant));
        $this->assertSame('2026-11-07', $this->today('UTC')->forStore(2, $instant));
        $this->assertSame('2026-11-08', $this->today('Pacific/Auckland')->forStore(2, '2026-11-07 11:30:00'));
    }

    public function testItUsesTheCurrentTimeWhenNoInstantIsGiven(): void
    {
        $expected = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
        $this->assertContains(
            $this->today('Asia/Tokyo')->forStore(2),
            [$expected, (new \DateTimeImmutable('+1 minute', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d')]
        );
    }

    public function testItFallsBackToUtcForAnUnusableTimezoneOrInstant(): void
    {
        $this->assertSame('2026-11-07', $this->today('Not/AZone')->forStore(2, '2026-11-07 23:30:00'));
        $this->assertSame('2026-11-07', $this->today(null)->forStore(2, '2026-11-07 23:30:00'));
        $this->assertSame('2026-11-07', $this->today(['x'])->forStore(2, '2026-11-07 23:30:00'));
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')))->format('Y-m-d');
        foreach (['garbage', '', '0000-00-00 00:00:00', "2026-11-07 23:30:00\n; DROP", '9999999999-01-01'] as $bad) {
            $this->assertContains($this->today('UTC')->forStore(2, $bad), [$now, $tomorrow], $bad);
        }
    }
}
