<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\Consent;
use Furan\FormBuilder\Model\FieldType\Matrix;
use Furan\FormBuilder\Model\FieldType\Number;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Radio;
use Furan\FormBuilder\Model\FieldType\Scale;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\Rule\Matches;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RulePool;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerNewTypesTest extends TestCase
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
                'number' => new Number(),
                'radio' => new Radio(),
                'consent' => new Consent(),
                'scale' => new Scale(),
                'matrix' => new Matrix(),
            ]),
            new RulePool(['matches' => new Matches()]),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues(new RedirectValidator($manager)),
            new PersonalData(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()),
            new Sources(),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn()
        );
    }

    private function field(array $over = []): array
    {
        return $over + ['kind' => 'field', 'type' => 'text', 'name' => 'first_name', 'label' => 'First name'];
    }

    private function items(array ...$items): array
    {
        return $this->normalizer->normalize(['pages' => [['items' => $items]]])['pages'][0]['items'];
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

    public function testItKeepsOptionDescriptionsOnlyWhenPresent(): void
    {
        $out = $this->items($this->field(['type' => 'radio', 'options' => [
            ['label' => 'A', 'value' => 'a', 'description' => "  Plain\ntext <b>x</b> " . str_repeat('z', 300)],
            ['label' => 'B', 'value' => 'b'],
        ]]));

        $this->assertSame(255, mb_strlen($out[0]['options'][0]['description']));
        $this->assertStringStartsWith('Plain text <b>x</b>', $out[0]['options'][0]['description']);
        $this->assertSame(['label' => 'B', 'value' => 'b'], $out[0]['options'][1]);
    }

    public function testItNormalisesConsentSettingsAndAcceptsRelativeAndSameHostPrivacyUrls(): void
    {
        foreach (['/privacy', 'https://shop.test/privacy', ''] as $url) {
            $out = $this->items($this->field(['type' => 'consent', 'settings' => [
                'details' => str_repeat('d', 1200), 'privacy_url' => $url,
            ]]));
            $this->assertSame($url, $out[0]['settings']['privacy_url']);
            $this->assertSame(1000, mb_strlen($out[0]['settings']['details']));
            $this->assertSame('Privacy policy', $out[0]['settings']['privacy_label']);
        }
    }

    public function testItRejectsForeignAndScriptPrivacyUrls(): void
    {
        foreach (['https://evil.test/p', 'javascript:alert(1)', '//evil.test/p'] as $url) {
            $errors = $this->errorsOf($this->field(['type' => 'consent', 'settings' => ['privacy_url' => $url]]));
            $this->assertStringContainsString('privacy_url', $errors[0], $url);
        }
    }

    public function testItClampsScaleSettingsAndLabelLengths(): void
    {
        $out = $this->items($this->field(['type' => 'scale', 'settings' => [
            'min' => 7, 'max' => 99, 'low_label' => str_repeat('l', 90),
        ]]));

        $this->assertSame(1, $out[0]['settings']['min']);
        $this->assertSame(10, $out[0]['settings']['max']);
        $this->assertSame(60, mb_strlen($out[0]['settings']['low_label']));
        $this->assertSame('', $out[0]['settings']['high_label']);
    }

    public function testItLimitsPrefixAndSuffixToTwentyCharacters(): void
    {
        $out = $this->items($this->field(['type' => 'number', 'name' => 'n', 'settings' => [
            'prefix' => str_repeat('p', 30), 'suffix' => 'kg',
        ]]));

        $this->assertSame(20, mb_strlen($out[0]['settings']['prefix']));
        $this->assertSame('kg', $out[0]['settings']['suffix']);
        $this->assertSame('any', $out[0]['settings']['step']);
    }

    public function testItRequiresOneToTwentyMatrixRows(): void
    {
        $options = [['label' => 'Yes', 'value' => 'y']];
        $out = $this->items($this->field(['type' => 'matrix', 'options' => $options, 'settings' => [
            'rows' => array_merge(['', 'Speed'], array_map(fn (int $i): string => "R$i", range(1, 30))),
        ]]));
        $this->assertCount(20, $out[0]['settings']['rows']);
        $this->assertSame('Speed', $out[0]['settings']['rows'][0]);
        $this->assertTrue($out[0]['settings']['require_all']);

        $errors = $this->errorsOf($this->field(['type' => 'matrix', 'options' => $options, 'settings' => ['rows' => ['']]]));
        $this->assertStringContainsString('rows', $errors[0]);
    }

    public function testItRejectsMatrixFieldsInConditionsAndMatchesRules(): void
    {
        $matrix = $this->field(['type' => 'matrix', 'name' => 'grid', 'label' => 'Grid',
            'options' => [['label' => 'Yes', 'value' => 'y']], 'settings' => ['rows' => ['A']]]);

        $conditional = $this->field(['name' => 'later', 'condition' => [
            'rules' => [['field' => 'grid', 'operator' => 'equals', 'value' => 'x']],
        ]]);
        $this->assertNotEmpty($this->errorsOf($matrix, $conditional));

        $matching = $this->field(['name' => 'later', 'rules' => [['rule' => 'matches', 'value' => 'grid']]]);
        $this->assertNotEmpty($this->errorsOf($matrix, $matching));
    }
}
