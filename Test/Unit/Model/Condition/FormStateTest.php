<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Condition;

use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Test\Unit\Helper\ActionPools;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use PHPUnit\Framework\TestCase;

class FormStateTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private static array $cases = [];

    private function evaluator(): Evaluator
    {
        return new Evaluator(OperatorPools::builtIn(), FieldPools::builtIn(), ActionPools::builtIn());
    }

    /** @return array<int, array<string, mixed>> */
    private static function cases(): array
    {
        if (self::$cases === []) {
            self::$cases = json_decode(
                (string)file_get_contents(__DIR__ . '/../../../fixtures/form-state.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }
        return self::$cases;
    }

    /** @param array<string, mixed> $case */
    private function assertCase(array $case): void
    {
        $state = $this->evaluator()->computeState($case['schema'], $case['values'], $case['today'] ?? null, $case['exempt'] ?? []);
        $actual = [
            'visible' => $state->visibility(),
            'required' => $state->required(),
            'values' => $state->values(),
        ];
        foreach ($case['expected'] as $key => $expected) {
            if ($key === 'defaults' || $key === 'hidden_options') {
                $this->assertSame($expected === [] ? [] : $expected, $key === 'defaults' ? $state->defaults() : $state->hiddenOptions(), $case['name'] . ': ' . $key);
                continue;
            }
            foreach ($expected as $id => $value) {
                $this->assertArrayHasKey($id, $actual[$key], $case['name'] . ': ' . $key . ' ' . $id);
                $this->assertSame($value, $actual[$key][$id], $case['name'] . ': ' . $key . ' ' . $id);
            }
        }
    }

    private function assertNamed(string ...$names): void
    {
        $found = 0;
        foreach (self::cases() as $case) {
            if (in_array($case['name'], $names, true)) {
                $this->assertCase($case);
                $found++;
            }
        }
        $this->assertSame(count($names), $found, 'fixture case missing');
    }

    public function testItPassesTheSharedFormStateFixtures(): void
    {
        $this->assertGreaterThanOrEqual(25, count(self::cases()));
        foreach (self::cases() as $case) {
            $this->assertCase($case);
        }
    }

    public function testItComputesVisibilityThroughTheActionPool(): void
    {
        $this->assertNamed(
            'show action hides the item when the rules do not match',
            'hide action hides the item when the rules match',
            'an item without a show or hide action stays visible',
            'the actions list wins over a stale single action',
            'an unknown action code changes nothing'
        );
    }

    public function testItRequiresAFieldWhenARequireActionMatches(): void
    {
        $this->assertNamed(
            'require action makes the field required when it matches',
            'require action keeps the own required flag when it does not match'
        );
    }

    public function testItOverwritesALockedFieldWithASetValueAction(): void
    {
        $this->assertNamed('set value overwrites a locked field', 'set value overwrites a hidden type field');
    }

    public function testItKeepsThePostedValueForAnEditableFieldWithASetValueAction(): void
    {
        $this->assertNamed('set value on an editable field is only a default', 'set value does nothing when the rules do not match');
    }

    public function testItAppliesASetValueBeforeLaterConditionsReadIt(): void
    {
        $this->assertNamed('set value is visible to later conditions', 'set value on a hidden item is ignored');
    }

    public function testItLetsASkipConditionReferenceFieldsOnItsOwnPage(): void
    {
        $this->assertNamed('a skip condition can reference fields on its own page', 'a skip that does not match skips nothing');
    }

    public function testItIgnoresTheSkipOfAPageThatIsItselfHidden(): void
    {
        $this->assertNamed('the skip of a hidden page is ignored');
    }

    public function testItAcceptsAPageWithBothAShowConditionAndASkipCondition(): void
    {
        $this->assertNamed('a page can have both a show condition and a skip condition');
    }

    public function testItSkipsOverThePagesBetweenAMatchedSkipAndItsTarget(): void
    {
        $this->assertNamed(
            'a skip condition can reference fields on its own page',
            'fields on skipped pages count as empty for later conditions',
            'a skip falls through when its target page is hidden by its own condition',
            'overlapping skips use the first matched skip and ignore skips on pages it hides',
            'a skip with an empty rules list never skips'
        );
    }

    public function testItKeepsAnExemptFieldOptionalEvenWhenARequireActionMatches(): void
    {
        $this->assertNamed('an exempt field is never required');
    }

    public function testItHidesOptionsOnlyWhenTheActionMatches(): void
    {
        $this->assertNamed(
            'hide options action hides the options when it matches',
            'hide options action does nothing when it does not match'
        );
    }
}
