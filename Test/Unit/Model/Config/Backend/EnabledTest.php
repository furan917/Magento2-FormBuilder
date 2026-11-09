<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Config\Backend;

use Furan\FormBuilder\Model\Config\Backend\Enabled;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class EnabledTest extends TestCase
{
    private array $cleaned = [];
    private array $events = [];

    private function model(string $old): Enabled
    {
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data = []): void {
            $this->events[] = [$name, $data['type'] ?? null];
        });
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($events);
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn($old);
        $types = $this->createStub(TypeListInterface::class);
        $types->method('cleanType')->willReturnCallback(function (string $type): void {
            $this->cleaned[] = $type;
        });
        $model = new Enabled($context, $this->createStub(Registry::class), $scope, $types);
        $model->setPath('furan_formbuilder/general/enabled')->setScope('default')->setScopeCode('');

        return $model;
    }

    public function testItFlushesPageAndBlockCachesIncludingVarnishWhenTheSwitchChanges(): void
    {
        $this->model('1')->setValue('0')->afterSave();

        $this->assertSame(['full_page', 'block_html'], $this->cleaned);
        $this->assertContains(['adminhtml_cache_refresh_type', 'full_page'], $this->events);
    }

    public function testItLeavesCachesAloneWhenTheValueIsUnchanged(): void
    {
        $this->model('1')->setValue('1')->afterSave();

        $this->assertSame([], $this->cleaned);
    }

    public function testItFlushesWhenAScopeFallsBackToTheParentValue(): void
    {
        $this->model('0')->afterDelete();

        $this->assertSame(['full_page', 'block_html'], $this->cleaned);
    }

    public function testTheSwitchCanBeSetPerWebsiteAndStoreView(): void
    {
        $system = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $field = $system->xpath('//section[@id="furan_formbuilder"]/group[@id="general"]/field[@id="enabled"]')[0];
        $this->assertSame(['1', '1', '1'], [(string)$field['showInDefault'], (string)$field['showInWebsite'], (string)$field['showInStore']]);
        $this->assertSame(Enabled::class, (string)$field->backend_model);
        $this->assertSame('1', (string)simplexml_load_file(__DIR__ . '/../../../../../etc/config.xml')->default->furan_formbuilder->general->enabled);
    }
}
