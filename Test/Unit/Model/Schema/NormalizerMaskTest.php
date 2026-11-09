<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Tel;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\Collection;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\CollectionFactory;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\BuiltInRules;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\Rule\Preset;
use Furan\FormBuilder\Model\Validation\RuleLibrary;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerMaskTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(static fn () => new \ArrayIterator([]));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $matcher = new PatternMatcher(new RepeatAnalyzer());
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([]);
        $this->normalizer = new Normalizer(
            new Pool(['text' => new Text(), 'tel' => new Tel()]),
            new RulePool(['preset' => new Preset(new RuleLibrary(new BuiltInRules(), $factory, $matcher), $matcher)]),
            $matcher,
            new SettingValues(new RedirectValidator($manager)),
            new PersonalData(FieldPools::builtIn()),
            new Sources(),
            ConditionNormalizers::builtIn()
        );
    }

    private function field(array $settings, array $rules = [], string $type = 'text'): array
    {
        return ['pages' => [['title' => 'P', 'items' => [[
            'kind' => 'field', 'type' => $type, 'name' => 'code', 'label' => 'Code',
            'settings' => $settings, 'rules' => $rules,
        ]]]]];
    }

    private function errorsOf(array $raw): array
    {
        try {
            $this->normalizer->normalize($raw);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    public function testItSavesAPresetMaskOnATextField(): void
    {
        $schema = $this->normalizer->normalize($this->field(['mask' => 'uk_postcode']));
        $this->assertSame('uk_postcode', $schema['pages'][0]['items'][0]['settings']['mask']);
    }

    public function testItSavesACustomMaskOnATelField(): void
    {
        $schema = $this->normalizer->normalize($this->field(['mask' => '(999) 999-9999'], [], 'tel'));
        $this->assertSame('(999) 999-9999', $schema['pages'][0]['items'][0]['settings']['mask']);
    }

    public function testItRejectsACustomMaskWithUnsupportedCharacters(): void
    {
        foreach (['99X99', '99<9', '99\\9', "99\n9", '99é9', 'xyz', '--', str_repeat('9', 41)] as $mask) {
            $errors = $this->errorsOf($this->field(['mask' => $mask]));
            $this->assertStringContainsString('mask', implode(' ', $errors), $mask);
        }
    }

    public function testItAcceptsACustomMaskOfPlaceholdersSpacesAndPunctuation(): void
    {
        foreach (['(999) 999-9999', 'aa 99 +./,:#_', str_repeat('9', 40), '**-**'] as $mask) {
            $schema = $this->normalizer->normalize($this->field(['mask' => $mask]));
            $this->assertSame($mask, $schema['pages'][0]['items'][0]['settings']['mask']);
        }
    }

    public function testItAcceptsACustomMaskWithCapitalLetterTokensOnSave(): void
    {
        foreach (['AA-9999', 'Aa 99', 'A9A 9A9'] as $mask) {
            $schema = $this->normalizer->normalize($this->field(['mask' => $mask]));
            $this->assertSame($mask, $schema['pages'][0]['items'][0]['settings']['mask']);
        }
    }

    public function testItRejectsAMaskWhoseOutputFailsTheFieldsPresetRule(): void
    {
        $sortCode = [['rule' => 'preset', 'value' => 'uk_sort_code']];
        $errors = $this->errorsOf($this->field(['mask' => '99 99 99'], $sortCode));
        $this->assertStringContainsString('mask', implode(' ', $errors));
        $this->assertStringContainsString('Code', implode(' ', $errors));
    }

    public function testItAcceptsAMaskWhoseOutputPassesTheFieldsPresetRule(): void
    {
        $cases = [
            ['uk_sort_code', 'uk_sort_code'], ['99-99-99', 'uk_sort_code'],
            ['uk_postcode', 'uk_postcode'], ['uk_phone', 'uk_phone'], ['international_phone', 'international_phone'],
            ['9999 999999', 'uk_phone'],
        ];
        foreach ($cases as [$mask, $preset]) {
            $schema = $this->normalizer->normalize($this->field(['mask' => $mask], [['rule' => 'preset', 'value' => $preset]]));
            $this->assertSame($mask, $schema['pages'][0]['items'][0]['settings']['mask'], $mask . ' ' . $preset);
        }
    }
}
