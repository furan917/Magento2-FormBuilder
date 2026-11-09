<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Form;

use Furan\FormBuilder\Model\Form\ScheduleTimezone;
use Magento\Config\Model\Config\Source\Locale\Timezone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ScheduleTimezoneTest extends TestCase
{
    /** @param array<int, string> $zones store id => zone */
    private function resolver(array $zones, string $default = 'America/Chicago', array $options = []): ScheduleTimezone
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope = 'default', $id = null) => $id === null ? $default : ($zones[(int)$id] ?? $default)
        );
        $stores = [];
        foreach (array_keys($zones) as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[$id] = $store;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn($stores);
        $source = $this->createStub(Timezone::class);
        $source->method('toOptionArray')->willReturn($options);
        return new ScheduleTimezone($config, $manager, $source);
    }

    public function testItUsesTheZoneOfASingleStoreView(): void
    {
        $this->assertSame('Europe/Paris', $this->resolver([1 => 'Europe/London', 2 => 'Europe/Paris'])->defaultFor([2]));
    }

    public function testItUsesTheSharedZoneWhenAllAssignedStoreViewsAgree(): void
    {
        $resolver = $this->resolver([1 => 'Europe/London', 2 => 'Europe/London', 3 => 'Asia/Tokyo']);

        $this->assertSame('Europe/London', $resolver->defaultFor([1, 2]));
    }

    public function testItFallsBackToTheDefaultScopeWhenZonesDiffer(): void
    {
        $resolver = $this->resolver([1 => 'Europe/London', 2 => 'Asia/Tokyo']);

        $this->assertSame('America/Chicago', $resolver->defaultFor([1, 2]));
        $this->assertSame('America/Chicago', $resolver->defaultFor([0]));
        $this->assertSame('America/Chicago', $resolver->defaultFor([]));
    }

    public function testItResolvesAllStoreViewsForStoreZero(): void
    {
        $this->assertSame('Asia/Tokyo', $this->resolver([1 => 'Asia/Tokyo', 2 => 'Asia/Tokyo'])->defaultFor([0]));
    }

    public function testItListsOnlyIanaOptionsAndKeepsAnUnlistedCurrentZone(): void
    {
        $resolver = $this->resolver([], 'UTC', [
            ['value' => 'Europe/London', 'label' => 'London'],
            ['value' => 'EST', 'label' => 'Eastern'],
        ]);

        $this->assertSame(
            [['value' => 'Europe/London', 'label' => 'London'], ['value' => 'Asia/Calcutta', 'label' => 'Asia/Calcutta']],
            $resolver->options(['Asia/Calcutta', 'Europe/London', ''])
        );
    }
}
