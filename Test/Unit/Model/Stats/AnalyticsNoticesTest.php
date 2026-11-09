<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\AnalyticsNotices;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class AnalyticsNoticesTest extends TestCase
{
    private function notices(array|false $state): AnalyticsNotices
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn($state);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new AnalyticsNotices($resource);
    }

    public function testItShowsANoticeWhileTheFormNeedsOrIsRunningAStatisticsRebuild(): void
    {
        $needs = $this->notices(['needs_rebuild' => '1', 'rebuilding' => '0', 'rebuild_started_at' => null])->get(3, 0);
        $this->assertSame(['Statistics do not include submissions made before analytics was installed.'], array_map('strval', $needs));

        $running = $this->notices(['needs_rebuild' => '1', 'rebuilding' => '1', 'rebuild_started_at' => '2026-11-07 10:00:00'])->get(3, 0);
        $this->assertContains('A statistics rebuild has been running since 2026-11-07 10:00:00.', array_map('strval', $running));
    }

    public function testItReportsPendingUpdatesAndCapsTheCount(): void
    {
        $none = $this->notices(false)->get(3, 0);
        $this->assertSame([], $none);

        $some = $this->notices(false)->get(3, 42);
        $this->assertSame(['42 updates still being processed.'], array_map('strval', $some));

        $capped = $this->notices(false)->get(3, 10001);
        $this->assertSame(['10,000+ updates still being processed.'], array_map('strval', $capped));
    }
}
