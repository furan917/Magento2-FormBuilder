<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\Checkboxes;
use Furan\FormBuilder\Model\FieldType\Date;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Newsletter;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Newsletter\NewsletterBridge;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerActionsTest extends TestCase
{
    private function normalizer(): Normalizer
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([]);
        $bridge = $this->createStub(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn(true);
        $types = new Pool([
            'text' => new Text(), 'select' => new Select(), 'checkboxes' => new Checkboxes(), 'date' => new Date(),
            'hidden' => new Hidden(), 'file' => new File(), 'newsletter' => new Newsletter($bridge),
        ]);
        return new Normalizer(
            $types,
            new RulePool(),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues(new RedirectValidator($manager)),
            new PersonalData(FieldPools::builtIn()),
            new Sources(),
            ConditionNormalizers::builtIn([], $types)
        );
    }

    private function rule(string $field = 'source', string $operator = 'equals', string $value = 'x'): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    private function when(array $actions, ?array $rules = null): array
    {
        return ['actions' => $actions, 'match' => 'all', 'rules' => $rules ?? [$this->rule()]];
    }

    /** A "source" text field followed by a "target" field carrying the condition. */
    private function schema(array $target, array $extraItems = []): array
    {
        $field = ['kind' => 'field', 'type' => 'text', 'name' => 'source', 'label' => 'Source'];
        $options = [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']];
        $target += ['kind' => 'field', 'type' => 'text', 'name' => 'target', 'label' => 'Target'];
        if (in_array($target['type'], ['select', 'checkboxes'], true)) {
            $target += ['options' => $options];
        }
        return ['pages' => [['title' => 'P', 'items' => array_merge([$field, $target], $extraItems)]]];
    }

    /** @return string[] */
    private function errors(array $raw, ?array $stored = null): array
    {
        try {
            $this->normalizer()->normalize($raw, $stored);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    private function conditionOf(array $raw): array
    {
        return $this->normalizer()->normalize($raw)['pages'][0]['items'][1]['condition'];
    }

    public function testItStoresTheActionsOfACondition(): void
    {
        $condition = $this->conditionOf($this->schema([
            'type' => 'select',
            'condition' => $this->when([['action' => 'show'], ['action' => 'require'], ['action' => 'hide_options', 'param' => ['b', 'b']]]),
        ]));

        $this->assertSame(
            [['action' => 'show'], ['action' => 'require'], ['action' => 'hide_options', 'param' => ['b']]],
            $condition['actions']
        );
        $this->assertArrayNotHasKey('action', $condition);
    }

    public function testItShowsTheItemWhenAConditionHasNoActions(): void
    {
        $condition = $this->conditionOf($this->schema(['condition' => ['action' => 'hide', 'match' => 'all', 'rules' => [$this->rule()]]]));
        $this->assertSame([['action' => 'show']], $condition['actions']);
    }

    public function testItRejectsARequireActionTargetingANewsletterField(): void
    {
        $this->assertSame(
            ['"Target": consent cannot be required or pre-filled by a condition.'],
            $this->errors($this->schema(['type' => 'newsletter', 'condition' => $this->when([['action' => 'require']])]))
        );
    }

    public function testItRejectsASetValueActionTargetingANewsletterField(): void
    {
        $this->assertSame(
            ['"Target": consent cannot be required or pre-filled by a condition.'],
            $this->errors($this->schema(['type' => 'newsletter', 'condition' => $this->when([['action' => 'set_value', 'param' => 'yes']])]))
        );
    }

    public function testItRejectsASetValueThatIsNotAnOptionOfTheTargetField(): void
    {
        $this->assertSame(
            ['"Target": the value to set is not valid for this field.'],
            $this->errors($this->schema(['type' => 'select', 'condition' => $this->when([['action' => 'set_value', 'param' => 'zzz']])]))
        );
        $ok = $this->conditionOf($this->schema(['type' => 'select', 'condition' => $this->when([['action' => 'set_value', 'param' => 'a']])]));
        $this->assertSame([['action' => 'set_value', 'param' => 'a']], $ok['actions']);
    }

    public function testItRejectsASetValueThatBreaksTheTypeLengthCapOrTheFieldKind(): void
    {
        $long = str_repeat('x', 300);
        $this->assertSame(
            ['"Target": the value to set is not valid for this field.'],
            $this->errors($this->schema(['type' => 'hidden', 'condition' => $this->when([['action' => 'set_value', 'param' => $long]])]))
        );
        foreach (['file', 'checkboxes'] as $type) {
            $this->assertNotEmpty($this->errors($this->schema(['type' => $type, 'condition' => $this->when([['action' => 'set_value', 'param' => 'a']])])));
        }
    }

    public function testItRejectsHideOptionsOutsideTheFieldOptions(): void
    {
        $this->assertSame(
            ['"Target": the options to hide must be options of this field.'],
            $this->errors($this->schema(['type' => 'select', 'condition' => $this->when([['action' => 'hide_options', 'param' => ['a', 'zzz']]])]))
        );
        $this->assertNotEmpty($this->errors($this->schema(['type' => 'text', 'condition' => $this->when([['action' => 'hide_options', 'param' => ['a']]])])));
        $this->assertNotEmpty($this->errors($this->schema(['type' => 'select', 'condition' => $this->when([['action' => 'hide_options', 'param' => []]])])));
    }

    public function testItRejectsAnActionThatCannotTargetTheItemKind(): void
    {
        $content = ['kind' => 'content', 'type' => 'paragraph', 'text' => 'Hi', 'condition' => $this->when([['action' => 'require']])];
        $raw = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'source', 'label' => 'Source'], $content,
        ]]]];
        $this->assertCount(1, $this->errors($raw));
    }

    public function testItRejectsMixingShowAndHideTooManyActionsAndRepeats(): void
    {
        $this->assertCount(1, $this->errors($this->schema(['condition' => $this->when([['action' => 'show'], ['action' => 'hide']])])));
        $this->assertCount(1, $this->errors($this->schema(['condition' => $this->when([['action' => 'require'], ['action' => 'require']])])));
        $many = array_fill(0, 11, ['action' => 'show']);
        $this->assertNotEmpty($this->errors($this->schema(['condition' => $this->when($many)])));
    }

    public function testItRejectsAnUnknownActionUnlessTheSavedItemHoldsTheSameOne(): void
    {
        $raw = $this->schema(['condition' => $this->when([['action' => 'sparkle', 'param' => 'x']])]);
        $this->assertSame(['"Target": unknown condition action.'], $this->errors($raw));

        $stored = $this->schema(['condition' => $this->when([['action' => 'sparkle', 'param' => 'x']])]);
        $stored['pages'][0]['items'][1]['id'] = 'f_target1';
        $raw['pages'][0]['items'][1]['id'] = 'f_target1';
        $kept = $this->normalizer()->normalize($raw, $stored)['pages'][0]['items'][1]['condition'];
        $this->assertSame([['action' => 'sparkle', 'param' => 'x']], $kept['actions']);

        $changed = $raw;
        $changed['pages'][0]['items'][1]['condition']['actions'][0]['param'] = 'y';
        $this->assertNotEmpty($this->errors($changed, $stored));
    }

    private function pages(array $first, array $second = [], array $third = []): array
    {
        $field = static fn (string $name): array => ['kind' => 'field', 'type' => 'text', 'name' => $name, 'label' => $name];
        $pages = [
            ['id' => 'p_first1', 'title' => 'First', 'items' => [$field('a')]] + $first,
            ['id' => 'p_second', 'title' => 'Second', 'items' => [$field('b')]] + $second,
            ['id' => 'p_third1', 'title' => 'Third', 'items' => [$field('c')]] + $third,
        ];
        return ['pages' => $pages];
    }

    private function skip(string $target, array $rules, array $extra = []): array
    {
        return ['skip' => ['match' => 'all', 'rules' => $rules, 'target' => $target] + $extra];
    }

    public function testItLetsASkipConditionReferenceFieldsOnItsOwnPage(): void
    {
        $pages = $this->normalizer()->normalize($this->pages($this->skip('p_third1', [$this->rule('a')])))['pages'];

        $this->assertSame(
            ['match' => 'all', 'rules' => [$this->rule('a')], 'target' => 'p_third1'],
            $pages[0]['skip']
        );
        $this->assertNull($pages[1]['skip']);
    }

    public function testItRejectsASkipConditionReferencingAFieldAfterItsPage(): void
    {
        $this->assertSame(
            ['"First": a condition can only use fields that appear earlier in the form.'],
            $this->errors($this->pages($this->skip('p_third1', [$this->rule('b')])))
        );
    }

    public function testItRejectsASkipWhoseTargetIsUnknownTheSamePageOrAnEarlierPage(): void
    {
        $this->assertCount(1, $this->errors($this->pages($this->skip('p_gone01', [$this->rule('a')]))));
        $this->assertCount(1, $this->errors($this->pages($this->skip('p_first1', [$this->rule('a')]))));
        $this->assertCount(1, $this->errors($this->pages([], $this->skip('p_first1', [$this->rule('b')]))));
        $this->assertCount(1, $this->errors($this->pages($this->skip('', [$this->rule('a')]))));
    }

    public function testItRejectsASkipOnTheLastPage(): void
    {
        $this->assertCount(1, $this->errors($this->pages([], [], $this->skip('p_third1', [$this->rule('c')]))));
    }

    public function testItStoresASkipWithoutRulesAsNull(): void
    {
        foreach ([[], [['match' => 'all', 'rules' => []]]] as $rules) {
            $pages = $this->normalizer()->normalize($this->pages($this->skip('p_third1', $rules)))['pages'];
            $this->assertNull($pages[0]['skip']);
        }
        $this->assertNull($this->normalizer()->normalize($this->pages(['skip' => 'junk']))['pages'][0]['skip']);
    }

    public function testItDropsUnknownKeysAndActionsFromASkip(): void
    {
        $skip = $this->normalizer()->normalize(
            $this->pages($this->skip('p_third1', [$this->rule('a')], ['action' => 'hide', 'actions' => [['action' => 'hide']], 'x' => 1]))
        )['pages'][0]['skip'];

        $this->assertSame(['match', 'rules', 'target'], array_keys($skip));
    }

    public function testItAcceptsAPageWithBothAShowConditionAndASkipCondition(): void
    {
        $page = $this->normalizer()->normalize($this->pages([]))['pages'][0];
        $this->assertSame(['id', 'title', 'condition', 'skip', 'items'], array_keys($page));

        $second = $this->pages([], ['condition' => $this->when([['action' => 'show']], [$this->rule('a')])] + $this->skip('p_third1', [$this->rule('b')]));
        $normalized = $this->normalizer()->normalize($second)['pages'][1];
        $this->assertNotNull($normalized['condition']);
        $this->assertNotNull($normalized['skip']);
    }

    public function testItKeepsADisabledOperatorRuleInsideASavedSkipCondition(): void
    {
        $rule = ['field' => 'a', 'operator' => 'sounds_like', 'value' => 'x'];
        $raw = $this->pages($this->skip('p_third1', [$rule]));
        $this->assertSame(['"First": unknown condition operator.'], $this->errors($raw));

        $stored = $this->pages($this->skip('p_third1', [['match' => 'any', 'rules' => [$rule]]]));
        $kept = $this->normalizer()->normalize($raw, $stored)['pages'][0]['skip'];
        $this->assertSame([$rule], $kept['rules']);
    }

    public function testItRefusesPageOnlyActionsWhenAskedAndRulesOnlyModeDropsActions(): void
    {
        $page = new class extends \Furan\FormBuilder\Model\Condition\Action\Show {
            public function getCode(): string
            {
                return 'page_only';
            }

            public function targets(): array
            {
                return ['page'];
            }
        };
        $conditions = ConditionNormalizers::builtIn([], null, ['page_only' => $page]);
        $sources = ['a' => 'text'];
        $errors = [];
        $raw = $this->when([['action' => 'page_only']], [$this->rule('a')]);

        $allowed = $conditions->normalize($raw, $sources, [], 'Item', $errors, ['kind' => 'page']);
        $this->assertSame([], $errors);
        $this->assertSame([['action' => 'page_only']], $allowed['actions']);

        $conditions->normalize($raw, $sources, [], 'Item', $errors, ['kind' => 'page', 'refuse_page_only' => true]);
        $this->assertCount(1, $errors);

        $errors = [];
        $plain = $conditions->normalizeRules($raw, $sources, [], 'Item', $errors);
        $this->assertSame(['match' => 'all', 'rules' => [$this->rule('a')]], $plain);
        $this->assertNull($conditions->normalizeRules(['rules' => []], $sources, [], 'Item', $errors));
    }
}
