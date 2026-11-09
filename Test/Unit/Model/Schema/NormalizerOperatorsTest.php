<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\Number;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ArrayAwareOperator;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerOperatorsTest extends TestCase
{
    private function normalizer(array $operators = []): Normalizer
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([$store]);
        return new Normalizer(
            new Pool(['text' => new Text(), 'number' => new Number()]),
            new RulePool(),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues(new RedirectValidator($manager)),
            new PersonalData(FieldPools::builtIn()),
            new Sources(),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn($operators)
        );
    }

    private function schema(string $operator, string $sourceType = 'text'): array
    {
        return ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => $sourceType, 'name' => 'source', 'label' => 'Source'],
            ['kind' => 'field', 'type' => 'text', 'name' => 'target', 'label' => 'Target', 'condition' => [
                'match' => 'all',
                'rules' => [['field' => 'source', 'operator' => $operator, 'value' => 'x']],
            ]],
        ]]]];
    }

    private function errors(Normalizer $normalizer, array $raw, ?array $stored = null): array
    {
        try {
            $normalizer->normalize($raw, $stored);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    public function testItAcceptsAConditionUsingARegisteredCustomOperatorOnSave(): void
    {
        $custom = new ArrayAwareOperator();
        $result = $this->normalizer([$custom->getCode() => $custom])->normalize($this->schema('sounds_like'));

        $this->assertSame('sounds_like', $result['pages'][0]['items'][1]['condition']['rules'][0]['operator']);
    }

    public function testItRejectsAConditionUsingAnOperatorThatIsNotInThePool(): void
    {
        $this->assertSame(
            ['"Target": unknown condition operator.'],
            $this->errors($this->normalizer(), $this->schema('sounds_like'))
        );
    }

    public function testItRejectsAnOperatorThatDoesNotApplyToTheReferencedFieldType(): void
    {
        $custom = new ArrayAwareOperator('sounds_like', ['number']);
        $normalizer = $this->normalizer([$custom->getCode() => $custom]);

        $this->assertNotEmpty($this->errors($normalizer, $this->schema('sounds_like', 'text')));
        $result = $normalizer->normalize($this->schema('sounds_like', 'number'));
        $this->assertSame('sounds_like', $result['pages'][0]['items'][1]['condition']['rules'][0]['operator']);
    }

    public function testItKeepsAStoredConditionWhoseOperatorIsNoLongerRegistered(): void
    {
        $custom = new ArrayAwareOperator();
        $stored = $this->normalizer([$custom->getCode() => $custom])->normalize($this->schema('sounds_like'));
        $posted = $stored;
        $posted['pages'][0]['items'][0]['label'] = 'Renamed';

        $result = $this->normalizer()->normalize($posted, $stored);

        $this->assertSame($stored['pages'][0]['items'][1]['condition'], $result['pages'][0]['items'][1]['condition']);

        $posted['pages'][0]['items'][1]['condition']['rules'][0]['value'] = 'changed';
        $this->assertNotEmpty($this->errors($this->normalizer(), $posted, $stored));
    }

    public function testItKeepsADisabledOperatorRuleInsideANestedGroupOfTheSavedItem(): void
    {
        $custom = new ArrayAwareOperator();
        $stored = $this->normalizer([$custom->getCode() => $custom])->normalize($this->schema('sounds_like'));
        $rule = $stored['pages'][0]['items'][1]['condition']['rules'][0];
        $stored['pages'][0]['items'][1]['condition']['rules'] = [['match' => 'all', 'rules' => [['match' => 'any', 'rules' => [$rule]]]]];
        $posted = $stored;
        $posted['pages'][0]['items'][0]['label'] = 'Renamed';

        $result = $this->normalizer()->normalize($posted, $stored);

        $this->assertSame($stored['pages'][0]['items'][1]['condition'], $result['pages'][0]['items'][1]['condition']);
    }

    public function testItStillSavesAFormThatUsesEveryBuiltInOperatorOnATextField(): void
    {
        $normalizer = $this->normalizer();
        foreach (array_keys(\Furan\FormBuilder\Test\Unit\Helper\OperatorPools::builtIn()->all()) as $code) {
            $result = $normalizer->normalize($this->schema($code));
            $this->assertSame($code, $result['pages'][0]['items'][1]['condition']['rules'][0]['operator']);
        }
        $this->assertCount(14, \Furan\FormBuilder\Test\Unit\Helper\OperatorPools::builtIn()->all());
    }
}
