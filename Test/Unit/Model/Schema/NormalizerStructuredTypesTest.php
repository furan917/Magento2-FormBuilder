<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\Address;
use Furan\FormBuilder\Model\Directory\AddressRules;
use Furan\FormBuilder\Model\FieldType\DateRange;
use Furan\FormBuilder\Model\FieldType\Map;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\Rule\Matches;
use Furan\FormBuilder\Model\Validation\RulePool;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizerStructuredTypesTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([$store]);
        $this->normalizer = new Normalizer(
            new Pool([
                'text' => new Text(),
                'opaque' => new class extends \Furan\FormBuilder\Model\FieldType\AbstractFieldType implements
                    \Furan\FormBuilder\Api\FieldType\ConditionSourceInterface
                {
                    public function getCode(): string
                    {
                        return 'opaque';
                    }

                    public function getLabel(): string
                    {
                        return 'Opaque';
                    }

                    public function canBeConditionSource(): bool
                    {
                        return false;
                    }
                },
                'date_range' => new DateRange(),
                'address' => new Address($this->createStub(AddressRules::class), \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create()),
                'map' => new Map($this->createStub(\Furan\FormBuilder\Model\Config::class), $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class), \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create(), \Furan\FormBuilder\Test\Unit\Helper\MapMarkerFactory::create()),
            ]),
            new RulePool(['matches' => new Matches()]),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues(new RedirectValidator($manager)),
            new PersonalData(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()),
            new Sources(),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn(),
            \Furan\FormBuilder\Test\Unit\Helper\FieldChecksFactory::create(),
        new \Furan\FormBuilder\Model\Validation\PresetFit()
);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function structuredTypes(): array
    {
        return ['date range' => ['date_range'], 'address' => ['address'], 'map' => ['map'], 'custom type' => ['opaque']];
    }

    private function structured(string $type): array
    {
        return ['kind' => 'field', 'type' => $type, 'name' => 'where', 'label' => 'Where'];
    }

    private function errorsOf(array ...$items): array
    {
        try {
            $this->normalizer->normalize(['pages' => [['items' => $items]]]);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    #[DataProvider('structuredTypes')]
    public function testItRejectsConditionsThatReferenceADateRangeAddressOrMapField(string $type): void
    {
        $later = ['kind' => 'field', 'type' => 'text', 'name' => 'later', 'label' => 'Later', 'condition' => [
            'rules' => [['field' => 'where', 'operator' => 'equals', 'value' => 'x']],
        ]];
        $this->assertNotEmpty($this->errorsOf($this->structured($type), $later));
    }

    #[DataProvider('structuredTypes')]
    public function testItRejectsAMatchesRuleThatNamesADateRangeAddressOrMapField(string $type): void
    {
        $later = ['kind' => 'field', 'type' => 'text', 'name' => 'later', 'label' => 'Later',
            'rules' => [['rule' => 'matches', 'value' => 'where']]];
        $this->assertNotEmpty($this->errorsOf($this->structured($type), $later));
    }
}
