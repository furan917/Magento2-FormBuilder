<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Rating;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\FieldType\Textarea;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new Normalizer(
            new Pool([
                'text' => new Text(),
                'textarea' => new Textarea(),
                'hidden' => new Hidden(),
                'file' => new File(),
                'rating' => new Rating(),
                'select' => new Select(),
            ]),
            new RulePool(),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues($this->redirectValidator()),
            new PersonalData(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()),
            \Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools::builtIn(),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn(),
            \Furan\FormBuilder\Test\Unit\Helper\FieldChecksFactory::create(),
        new \Furan\FormBuilder\Model\Validation\PresetFit()
);
    }

    private function redirectValidator(): RedirectValidator
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([$store]);
        return new RedirectValidator($manager);
    }

    private function field(array $over = []): array
    {
        return $over + ['kind' => 'field', 'type' => 'text', 'name' => 'first_name', 'label' => 'First name'];
    }

    private function wrap(array ...$items): array
    {
        return ['pages' => [['title' => 'P', 'items' => $items]]];
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

    public function testItReturnsACleanSchemaWithVersion1ForValidInput(): void
    {
        $result = $this->normalizer->normalize($this->wrap($this->field([
            'id' => 'f_abcd12', 'placeholder' => 'Jo', 'help' => 'Help', 'default' => 'x', 'width' => 'half',
            'required' => true, 'autocomplete' => 'given-name', 'css_class' => 'my-class',
        ])));

        $this->assertSame(1, $result['version']);
        $page = $result['pages'][0];
        $this->assertMatchesRegularExpression('/^p_[a-z0-9]{4,16}$/D', $page['id']);
        $this->assertSame('P', $page['title']);
        $this->assertNull($page['condition']);
        $this->assertSame([
            'id' => 'f_abcd12', 'kind' => 'field', 'type' => 'text', 'name' => 'first_name',
            'label' => 'First name', 'placeholder' => 'Jo', 'help' => 'Help', 'default' => 'x',
            'width' => 'half', 'required' => true, 'rules' => [], 'options' => [],
            'settings' => ['prefix' => '', 'suffix' => '', 'mask' => ''],
            'condition' => null, 'draft' => true, 'autocomplete' => 'given-name', 'css_class' => 'my-class',
            'personal' => true, 'prefill' => '', 'locked' => false,
        ], $page['items'][0]);
    }

    public function testItDefaultsThePersonalDataFlagOnForEmailPhoneAddressMapAndFileFields(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['type' => 'file', 'name' => 'upload']),
            $this->field(['autocomplete' => 'email', 'name' => 'mail'])
        ))['pages'][0]['items'];
        $this->assertTrue($out[0]['personal']);
        $this->assertTrue($out[1]['personal']);
    }

    public function testItDefaultsThePersonalDataFlagOffForChoiceFields(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['type' => 'select', 'name' => 'pick', 'options' => [['label' => 'A', 'value' => 'a']]])
        ))['pages'][0]['items'];
        $this->assertFalse($out[0]['personal']);
    }

    public function testItKeepsAnExplicitPersonalDataChoiceFromTheBuilder(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['type' => 'file', 'name' => 'upload', 'personal' => false]),
            $this->field(['name' => 'note', 'personal' => true])
        ))['pages'][0]['items'];
        $this->assertFalse($out[0]['personal']);
        $this->assertTrue($out[1]['personal']);
    }

    public function testItKeepsAKnownPrefillSourceOnACompatibleField(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['prefill' => 'customer.firstname']),
            $this->field(['name' => 'plain'])
        ))['pages'][0]['items'];
        $this->assertSame('customer.firstname', $out[0]['prefill']);
        $this->assertSame('', $out[1]['prefill']);
    }

    public function testItDropsAPrefillSourceThatDoesNotSuitTheFieldType(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['type' => 'select', 'name' => 'pick', 'options' => [['label' => 'A', 'value' => 'a']],
                'prefill' => 'customer.firstname', 'locked' => true]),
            $this->field(['name' => 'bogus', 'prefill' => 'customer.nope', 'locked' => true]),
            $this->field(['name' => 'addr', 'prefill' => 'address.billing'])
        ))['pages'][0]['items'];
        foreach ($out as $item) {
            $this->assertSame('', $item['prefill']);
            $this->assertFalse($item['locked']);
        }
    }

    public function testItRejectsAPrefillMappingToASourceThatIsNotInThePoolOnSave(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['prefill' => 'loyalty.number', 'locked' => true])
        ))['pages'][0]['items'];
        $this->assertSame('', $out[0]['prefill']);
        $this->assertFalse($out[0]['locked']);
    }

    public function testItKeepsAStoredPrefillMappingWhoseSourceIsNoLongerRegistered(): void
    {
        $gone = $this->field(['id' => 'f_aaaa1', 'prefill' => 'loyalty.number', 'locked' => true]);
        $stored = $this->wrap($gone);
        $other = $this->field(['id' => 'f_bbbb2', 'name' => 'other', 'prefill' => 'loyalty.number']);

        $out = $this->normalizer->normalize($this->wrap($gone, $other), $stored)['pages'][0]['items'];

        $this->assertSame('loyalty.number', $out[0]['prefill']);
        $this->assertTrue($out[0]['locked']);
        $this->assertSame('', $out[1]['prefill']);
    }

    public function testItKeepsTheLockedFlagOnlyWhenAPrefillSourceIsSet(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['name' => 'a', 'prefill' => 'customer.lastname', 'locked' => true]),
            $this->field(['name' => 'b', 'prefill' => 'customer.lastname']),
            $this->field(['name' => 'c', 'locked' => true])
        ))['pages'][0]['items'];
        $this->assertTrue($out[0]['locked']);
        $this->assertFalse($out[1]['locked']);
        $this->assertFalse($out[2]['locked']);
    }

    public function testItAlwaysLocksFieldsMappedToAProductSource(): void
    {
        $out = $this->normalizer->normalize($this->wrap(
            $this->field(['name' => 'price', 'prefill' => 'product.price', 'locked' => false]),
            $this->field(['type' => 'hidden', 'name' => 'sku', 'prefill' => 'product.sku'])
        ))['pages'][0]['items'];
        $this->assertTrue($out[0]['locked']);
        $this->assertTrue($out[1]['locked']);
    }

    public function testItDropsUnknownKeysFromPagesAndItems(): void
    {
        $raw = $this->wrap(
            $this->field(['evil' => '<script>', 'settings' => ['rogue' => 1]]),
            ['kind' => 'content', 'type' => 'divider', 'junk' => 1],
            ['kind' => 'section', 'title' => 'S', 'extra' => 1, 'items' => [$this->field(['name' => 'b', 'x' => 1])]]
        );
        $raw['pages'][0]['bogus'] = true;
        $raw['top'] = 'level';

        $result = $this->normalizer->normalize($raw);

        $this->assertSame(['version', 'pages'], array_keys($result));
        $this->assertSame(['id', 'title', 'condition', 'skip', 'items'], array_keys($result['pages'][0]));
        $items = $result['pages'][0]['items'];
        $this->assertArrayNotHasKey('evil', $items[0]);
        $this->assertSame(['prefix' => '', 'suffix' => '', 'mask' => ''], $items[0]['settings']);
        $this->assertSame(['id', 'kind', 'type', 'width', 'condition'], array_keys($items[1]));
        $this->assertSame(['id', 'kind', 'title', 'description', 'condition', 'items'], array_keys($items[2]));
        $this->assertArrayNotHasKey('x', $items[2]['items'][0]);
    }

    public function testItCreatesOneEmptyPageWhenNoPagesAreGiven(): void
    {
        foreach ([[], ['pages' => []], ['pages' => 'nope']] as $raw) {
            $result = $this->normalizer->normalize($raw);
            $this->assertCount(1, $result['pages']);
            $this->assertSame([], $result['pages'][0]['items']);
        }
    }

    public function testItRejectsMoreThan20PagesOr200Items(): void
    {
        $this->assertNotEmpty($this->errorsOf(['pages' => array_fill(0, 21, ['items' => []])]));

        $items = [];
        for ($i = 0; $i < 201; $i++) {
            $items[] = $this->field(['name' => 'f' . $i]);
        }
        $this->assertNotEmpty($this->errorsOf($this->wrap(...$items)));

        $items = array_slice($items, 0, 200);
        $this->assertCount(200, $this->normalizer->normalize($this->wrap(...$items))['pages'][0]['items']);
    }

    public function testItNormalizesRichTextNoticeAndSummaryContent(): void
    {
        $items = $this->normalizer->normalize($this->wrap(
            ['kind' => 'content', 'type' => 'rich_text', 'text' => "**a**\r\n\x00" . str_repeat('x', 6000), 'level' => 3, 'title' => 'drop'],
            ['kind' => 'content', 'type' => 'notice', 'text' => "line\nbreak" . str_repeat('n', 1100), 'style' => 'warning', 'title' => str_repeat('t', 300), 'bogus' => 1],
            ['kind' => 'content', 'type' => 'notice', 'text' => 'x', 'style' => '"><script>'],
            ['kind' => 'content', 'type' => 'summary', 'title' => ' Check ', 'text' => 'dropped'],
            ['kind' => 'content', 'type' => 'summary']
        ))['pages'][0]['items'];

        $this->assertSame(['id', 'kind', 'type', 'text', 'width', 'condition'], array_keys($items[0]));
        $this->assertSame(5000, mb_strlen($items[0]['text']));
        $this->assertStringStartsWith("**a**\n", $items[0]['text']);
        $this->assertSame(['id', 'kind', 'type', 'text', 'style', 'title', 'width', 'condition'], array_keys($items[1]));
        $this->assertSame(1000, mb_strlen($items[1]['text']));
        $this->assertStringStartsWith("line\nbreak", $items[1]['text']);
        $this->assertSame('warning', $items[1]['style']);
        $this->assertSame(255, mb_strlen($items[1]['title']));
        $this->assertSame('info', $items[2]['style']);
        $this->assertSame('', $items[2]['title']);
        $this->assertSame(['id', 'kind', 'type', 'title', 'width', 'condition'], array_keys($items[3]));
        $this->assertSame('Check', $items[3]['title']);
        $this->assertSame('Review your answers', $items[4]['title']);
    }

    public function testItRegeneratesInvalidAndDuplicateIdsWithTheRightPrefix(): void
    {
        $raw = $this->wrap(
            $this->field(['id' => 'f_abcd12']),
            $this->field(['id' => 'f_abcd12', 'name' => 'b']),
            $this->field(['id' => 'c_abcd12', 'name' => 'c']),
            $this->field(['id' => '../x', 'name' => 'd']),
            ['kind' => 'content', 'type' => 'spacer'],
            ['kind' => 'section', 'id' => 'f_abcd12', 'items' => []]
        );
        $raw['pages'][0]['id'] = 'p_ab';

        $page = $this->normalizer->normalize($raw)['pages'][0];

        $this->assertMatchesRegularExpression('/^p_[a-z0-9]{4,16}$/D', $page['id']);
        $ids = array_column($page['items'], 'id');
        $this->assertSame('f_abcd12', $ids[0]);
        $this->assertMatchesRegularExpression('/^f_[a-z0-9]{4,16}$/D', $ids[1]);
        $this->assertMatchesRegularExpression('/^f_[a-z0-9]{4,16}$/D', $ids[2]);
        $this->assertMatchesRegularExpression('/^f_[a-z0-9]{4,16}$/D', $ids[3]);
        $this->assertMatchesRegularExpression('/^c_[a-z0-9]{4,16}$/D', $ids[4]);
        $this->assertMatchesRegularExpression('/^s_[a-z0-9]{4,16}$/D', $ids[5]);
        $all = array_merge($ids, [$page['id']]);
        $this->assertSame($all, array_values(array_unique($all)));
    }

    public function testItRejectsUnknownFieldTypes(): void
    {
        $errors = $this->errorsOf($this->wrap($this->field(['type' => 'nope'])));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('nope', implode(' ', $errors));
    }

    public function testItRejectsInvalidDuplicateAndReservedFieldNames(): void
    {
        foreach (['', 'Upper', '1abc', 'a-b', str_repeat('a', 65), 'form_key', 'form_id', 'ffb_website'] as $name) {
            $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['name' => $name]))), 'name: ' . $name);
        }
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(), $this->field())));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['label' => '']))));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['name' => ['x']]))));
        // duplicate across sections
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(),
            ['kind' => 'section', 'items' => [$this->field()]]
        )));
    }

    public function testItRejectsSectionsNestedInsideSections(): void
    {
        $errors = $this->errorsOf($this->wrap(
            ['kind' => 'section', 'items' => [['kind' => 'section', 'items' => []]]]
        ));
        $this->assertNotEmpty($errors);
    }

    public function testItCoercesFieldSettingsUsingTheTypeSettingsSpec(): void
    {
        $result = $this->normalizer->normalize($this->wrap(
            $this->field(['type' => 'textarea', 'name' => 'a', 'settings' => ['rows' => '99', 'rogue' => 'x']]),
            $this->field(['type' => 'textarea', 'name' => 'b', 'settings' => ['rows' => 'abc']]),
            $this->field(['type' => 'textarea', 'name' => 'c', 'settings' => ['rows' => 1]]),
            $this->field(['type' => 'hidden', 'name' => 'd', 'settings' => ['source' => 'evil', 'query_param' => ' utm ']]),
            $this->field(['type' => 'hidden', 'name' => 'e', 'settings' => ['source' => 'query']]),
            $this->field(['type' => 'file', 'name' => 'f', 'settings' => ['extensions' => ['pdf', ['x'], 'png'], 'max_size_mb' => 500]]),
            $this->field(['type' => 'rating', 'name' => 'g', 'settings' => []])
        ));
        $items = $result['pages'][0]['items'];

        $this->assertSame(['rows' => 20], $items[0]['settings']);
        $this->assertSame(['rows' => 5], $items[1]['settings']);
        $this->assertSame(['rows' => 2], $items[2]['settings']);
        $this->assertSame(['source' => 'static', 'query_param' => 'utm'], $items[3]['settings']);
        $this->assertSame('query', $items[4]['settings']['source']);
        $this->assertSame(['extensions' => ['pdf', 'png'], 'max_size_mb' => 100], $items[5]['settings']);
        $this->assertSame(['max' => 5], $items[6]['settings']);
    }

    public function testItForcesDraftOffForFileAndHiddenFields(): void
    {
        $items = $this->normalizer->normalize($this->wrap(
            $this->field(['type' => 'file', 'name' => 'a', 'draft' => true]),
            $this->field(['type' => 'hidden', 'name' => 'b', 'draft' => true]),
            $this->field(['name' => 'c', 'draft' => false]),
            $this->field(['name' => 'd'])
        ))['pages'][0]['items'];

        $this->assertFalse($items[0]['draft']);
        $this->assertFalse($items[1]['draft']);
        $this->assertFalse($items[2]['draft']);
        $this->assertTrue($items[3]['draft']);
    }

    public function testItStripsControlCharactersAndCapsStringLengths(): void
    {
        $result = $this->normalizer->normalize($this->wrap(
            $this->field([
                'label' => "  Na\x00me\x07\n here  ", 'help' => "a\nb\x01" . str_repeat('h', 1100),
                'placeholder' => str_repeat('p', 300), 'width' => 'bogus', 'css_class' => 'bad"class',
                'autocomplete' => 'nonsense', 'required' => 'yes',
            ]),
            ['kind' => 'content', 'type' => 'heading', 'text' => str_repeat('é', 300), 'level' => 9],
            ['kind' => 'content', 'type' => 'paragraph', 'text' => "one\r\n\r\ntwo\x00", 'level' => 3],
            ['kind' => 'content', 'type' => 'heading', 'text' => 'H', 'level' => 'x', 'width' => 'third']
        ));
        $items = $result['pages'][0]['items'];

        $this->assertSame('Name  here', $items[0]['label']);
        $this->assertSame(1000, mb_strlen($items[0]['help']));
        $this->assertStringStartsWith("a\nbhhh", $items[0]['help']);
        $this->assertSame(255, mb_strlen($items[0]['placeholder']));
        $this->assertSame('full', $items[0]['width']);
        $this->assertSame('', $items[0]['css_class']);
        $this->assertSame('', $items[0]['autocomplete']);
        $this->assertTrue($items[0]['required']);
        $this->assertSame(255, mb_strlen($items[1]['text']));
        $this->assertSame(4, $items[1]['level']);
        $this->assertSame("one\n\ntwo", $items[2]['text']);
        $this->assertArrayNotHasKey('level', $items[2]);
        $this->assertSame(2, $items[3]['level']);
        $this->assertSame('third', $items[3]['width']);
    }

    public function testItRejectsUnknownContentTypesAndKinds(): void
    {
        $this->assertNotEmpty($this->errorsOf($this->wrap(['kind' => 'content', 'type' => 'bogus'])));
        $this->assertNotEmpty($this->errorsOf($this->wrap(['kind' => 'weird'])));
    }

    public function testItCollectsAllErrorsBeforeThrowing(): void
    {
        $errors = $this->errorsOf($this->wrap(
            $this->field(['type' => 'nope']),
            $this->field(['name' => 'Bad'])
        ));
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    /** @return array<string, mixed> */
    private function storedGone(): array
    {
        return ['id' => 'f_gone01', 'kind' => 'field', 'type' => 'gone', 'name' => 'extra', 'label' => 'Extra',
            'required' => true, 'settings' => ['mode' => 'x'], 'custom' => ['a' => 1], 'condition' => null];
    }

    private function storedSchema(array ...$items): array
    {
        return ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'title' => 'P', 'condition' => null, 'items' => $items]]];
    }

    public function testItKeepsAStoredUnknownTypeFieldUnchangedWhenTheFormIsSavedInTheBuilder(): void
    {
        $stored = $this->storedGone();
        $posted = ['id' => 'f_gone01', 'kind' => 'field', 'type' => 'gone', 'name' => 'extra', 'label' => 'TAMPERED',
            'required' => false, 'settings' => ['mode' => 'evil']];

        $result = $this->normalizer->normalize(
            $this->wrap($this->field(['name' => 'first']), $posted, $this->field(['name' => 'last'])),
            $this->storedSchema($stored)
        );

        $items = $result['pages'][0]['items'];
        $this->assertSame(['first', 'extra', 'last'], array_column($items, 'name'));
        $this->assertSame($stored, $items[1]);
    }

    public function testItLetsALaterConditionKeepReferencingAKeptUnknownTypeField(): void
    {
        $condition = ['match' => 'all',
            'rules' => [['field' => 'extra', 'operator' => 'equals', 'value' => 'y']]];

        $result = $this->normalizer->normalize(
            $this->wrap($this->storedGone(), $this->field(['condition' => $condition])),
            $this->storedSchema($this->storedGone())
        );

        $this->assertSame(['actions' => [['action' => 'show']]] + array_diff_key($condition, ['action' => 1]), $result['pages'][0]['items'][1]['condition']);
    }

    public function testItRejectsAnUnknownTypeFieldOnSaveWhenTheStoredSchemaDidNotAlreadyContainIt(): void
    {
        $posted = ['kind' => 'field', 'type' => 'gone', 'name' => 'extra', 'label' => 'Extra'];

        $this->assertStringContainsString('unknown type', $this->errorsOf($this->wrap($posted))[0]);
        try {
            $this->normalizer->normalize($this->wrap($posted), $this->storedSchema($this->field(['id' => 'f_zzzz1'])));
            $this->fail('Expected InvalidSchemaException');
        } catch (InvalidSchemaException $e) {
            $this->assertStringContainsString('unknown type', $e->getErrors()[0]);
        }
    }

    public function testItKeepsAPositiveFieldGroupMarkerOnFieldsContentAndSections(): void
    {
        $schema = $this->normalizer->normalize($this->wrap(
            $this->field(['source_group' => 4]),
            ['kind' => 'content', 'type' => 'heading', 'text' => 'H', 'source_group' => '7'],
            ['kind' => 'section', 'title' => 'S', 'source_group' => 9, 'items' => [$this->field(['name' => 'b', 'source_group' => 9])]]
        ));
        $items = $schema['pages'][0]['items'];

        $this->assertSame(4, $items[0]['source_group']);
        $this->assertSame(7, $items[1]['source_group']);
        $this->assertSame(9, $items[2]['source_group']);
        $this->assertSame(9, $items[2]['items'][0]['source_group']);
    }

    public function testItDropsAMalformedFieldGroupMarker(): void
    {
        foreach ([0, -2, 'x', 1.5, [3], true] as $bad) {
            $item = $this->normalizer->normalize($this->wrap($this->field(['source_group' => $bad])))['pages'][0]['items'][0];
            $this->assertArrayNotHasKey('source_group', $item, var_export($bad, true));
        }
    }
}
