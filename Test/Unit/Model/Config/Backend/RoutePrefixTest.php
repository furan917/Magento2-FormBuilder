<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Config\Backend;

use Furan\FormBuilder\Model\Config\Backend\RoutePrefix;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoutePrefixTest extends TestCase
{
    private function save(string $value): string
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        $model = new RoutePrefix(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $this->routes()
        );
        $model->setValue($value);
        $model->beforeSave();

        return (string)$model->getValue();
    }

    public function testItKeepsAValidPrefixTrimmed(): void
    {
        self::assertSame('contact-forms2', $this->save(' contact-forms2 '));
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return [
            'underscore' => ['qa_acl_forms'],
            'uppercase' => ['Forms'],
            'slash' => ['forms/contact'],
            'leading hyphen' => ['-forms'],
            'dot' => ['forms.html'],
            'too long' => [str_repeat('a', 33)],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalid')]
    public function testItRefusesAPrefixTheRouterWouldIgnore(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('lowercase letters, digits and hyphens');
        $this->save($value);
    }

    private array $cleaned = [];
    private array $events = [];

    private function stored(string $old): RoutePrefix
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
        $model = new RoutePrefix($context, $this->createStub(Registry::class), $scope, $types, $this->routes());
        $model->setPath('furan_formbuilder/general/route_prefix')->setScope('default')->setScopeCode('');

        return $model;
    }

    public function testItFlushesCachedPagesIncludingVarnishWhenThePrefixChanges(): void
    {
        $this->stored('forms')->setValue('contact')->afterSave();

        $this->assertSame(['full_page', 'block_html'], $this->cleaned);
        $this->assertContains(['adminhtml_cache_refresh_type', 'full_page'], $this->events);
    }

    public function testItLeavesCachesAloneWhenThePrefixIsUnchanged(): void
    {
        $this->stored('forms')->setValue('forms')->afterSave();

        $this->assertSame([], $this->cleaned);
    }

    public function testItFlushesWhenAScopeFallsBackToTheParentPrefix(): void
    {
        $this->stored('contact')->afterDelete();

        $this->assertSame(['full_page', 'block_html'], $this->cleaned);
    }

    private function routes(): \Magento\Framework\App\Route\ConfigInterface
    {
        $routes = $this->createStub(\Magento\Framework\App\Route\ConfigInterface::class);
        $routes->method('getRouteByFrontName')->willReturnCallback(
            static fn (string $frontName, ?string $scope = null) => $scope === 'frontend' && in_array($frontName, ['checkout', 'customer', 'furanform'], true)
                ? $frontName
                : false
        );
        return $routes;
    }

    public function testItRefusesAPrefixThatAStorefrontRouteAlreadyUses(): void
    {
        foreach (['checkout', 'customer', 'furanform'] as $prefix) {
            try {
                $this->save($prefix);
                self::fail($prefix . ' was accepted');
            } catch (LocalizedException $e) {
                self::assertStringContainsString('already used by another storefront page', $e->getMessage());
            }
        }
    }
}
