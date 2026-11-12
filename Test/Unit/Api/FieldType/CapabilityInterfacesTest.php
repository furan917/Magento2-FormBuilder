<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Api\FieldType;

use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;
use Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface;
use Furan\FormBuilder\Api\FieldType\ExportableTypeInterface;
use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\StorefrontConfigInterface;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CapabilityInterfacesTest extends TestCase
{
    /** @return array<string, array{class-string, array<string, array{string[], string}>}> */
    public static function contracts(): array
    {
        return [
            'exportable' => [ExportableTypeInterface::class, [
                'csvHeaders' => [['array'], 'array'],
                'csvCells' => [['array', 'mixed'], 'array'],
                'csvPlainColumns' => [['array'], 'array'],
            ]],
            'displayable' => [DisplayableTypeInterface::class, [
                'toText' => [['array', 'mixed', \Furan\FormBuilder\Api\Data\DisplayContext::class], 'string'],
                'toHtml' => [['array', 'mixed', \Magento\Framework\Escaper::class, \Furan\FormBuilder\Api\Data\DisplayContext::class], 'string'],
            ]],
            'client messages' => [ClientMessagesInterface::class, ['getClientMessages' => [['array'], 'array']]],
            'stats' => [StatsAwareTypeInterface::class, ['statsKind' => [['array'], '?int']]],
            'free text' => [FreeTextTypeInterface::class, ['isFreeText' => [[], 'bool']]],
            'personal' => [PersonalByDefaultInterface::class, ['isPersonalByDefault' => [[], 'bool']]],
            'condition source' => [ConditionSourceInterface::class, ['canBeConditionSource' => [[], 'bool']]],
            'routable' => [RoutableTypeInterface::class, ['canBeRouted' => [[], 'bool']]],
            'storefront config' => [StorefrontConfigInterface::class, ['storefrontConfig' => [['array', 'int'], 'array']]],
        ];
    }

    #[DataProvider('contracts')]
    public function testItDefinesTheCapabilityInterfacesInApiFieldTypeWithDocumentedContracts(
        string $interface,
        array $methods
    ): void {
        $this->assertTrue(interface_exists($interface), $interface);
        $reflection = new \ReflectionClass($interface);
        $doc = (string)$reflection->getDocComment();
        $this->assertStringContainsString('@api', $doc);
        $this->assertGreaterThan(30, strlen($doc));
        $this->assertSame(array_keys($methods), array_map(static fn ($m) => $m->getName(), $reflection->getMethods()));
        foreach ($methods as $name => [$params, $return]) {
            $method = $reflection->getMethod($name);
            $this->assertSame($params, array_map(static fn ($p) => (string)$p->getType(), $method->getParameters()), $name);
            $this->assertSame($return, (string)$method->getReturnType(), $name);
        }
    }

    public function testItKeepsStatsKindConstantsEqualToSeriesSpec(): void
    {
        $constants = (new \ReflectionClass(StatsAwareTypeInterface::class))->getConstants();
        $this->assertCount(10, $constants);
        foreach ($constants as $name => $value) {
            $this->assertSame(constant(SeriesSpec::class . '::' . $name), $value, $name);
        }
    }
}
