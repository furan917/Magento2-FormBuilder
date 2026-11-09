<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\System\Message;

use Furan\FormBuilder\Model\System\Message\StatsRebuildNeeded;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class StatsRebuildNeededTest extends TestCase
{
    private function message(bool $allowed, bool $hit): StatsRebuildNeeded
    {
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->with('Furan_FormBuilder::analytics')->willReturn($allowed);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->with($this->logicalAnd(
            $this->stringContains('needs_rebuild = 1 AND rebuild_requested = 0 AND rebuilding = 0'),
            $this->stringContains('LIMIT 1')
        ))->willReturn($hit ? '1' : false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->with('furanform/form/index')->willReturn('https://shop.test/admin/furanform/form/index/?a=1&b=2');
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($v) => htmlspecialchars((string)$v));
        $escaper->method('escapeUrl')->willReturnCallback(static fn ($v) => htmlspecialchars((string)$v));

        return new StatsRebuildNeeded($auth, $resource, $url, $escaper);
    }

    public function testItShowsAnAdminNoticeWithARebuildNowButtonWhileFormsNeedARebuild(): void
    {
        $message = $this->message(true, true);

        $this->assertTrue($message->isDisplayed());
        $this->assertSame('furan_formbuilder_stats_rebuild_needed', $message->getIdentity());
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertStringContainsString('Rebuild now', (string)$message->getText());
        $this->assertStringContainsString('href="https://shop.test/admin/furanform/form/index/?a=1&amp;b=2"', (string)$message->getText());
    }

    public function testItStaysHiddenWithoutThePermissionOrWhenNothingNeedsARebuild(): void
    {
        $this->assertFalse($this->message(false, true)->isDisplayed());
        $this->assertFalse($this->message(true, false)->isDisplayed());
    }

    public function testItRegistersTheNoticeForTheAdminAreaOnly(): void
    {
        $xpath = '/config/type[@name="Magento\Framework\Notification\MessageList"]/arguments/argument[@name="messages"]'
            . '/item[@name="furan_formbuilder_stats_rebuild_needed"]';
        $etc = __DIR__ . '/../../../../../etc/';

        $admin = simplexml_load_file($etc . 'adminhtml/di.xml')->xpath($xpath);
        $this->assertCount(1, $admin);
        $this->assertSame(StatsRebuildNeeded::class, trim((string)$admin[0]));
        $this->assertSame([], simplexml_load_file($etc . 'di.xml')->xpath($xpath));
    }
}
