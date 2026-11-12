<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Suggest;
use Furan\FormBuilder\Model\FieldType\SuggestionMatcher;
use PHPUnit\Framework\TestCase;

class SuggestMatchFixtureTest extends TestCase
{
    public function testItPassesEveryCaseInTheSharedSuggestMatchFixture(): void
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/suggest-match.json'), true);
        foreach ($fixture['cases'] as $case) {
            $this->assertSame($case['expected'], SuggestionMatcher::match($case['raw'], $case['options']), $case['name']);
        }
    }

    public function testSuggestStoresTheMatchedOptionValueAfterItsOwnNormalisation(): void
    {
        $field = ['options' => [['label' => 'Dark Blue', 'value' => 'navy']], 'settings' => []];
        $this->assertSame('navy', (new Suggest())->normalizeValue(" dark\x00 blue\n", $field));
    }

    public function testItAppliesToFreeTextTypesWithOptionsOnly(): void
    {
        $this->assertTrue(SuggestionMatcher::appliesTo(new Suggest()));
        $this->assertFalse(SuggestionMatcher::appliesTo(new \Furan\FormBuilder\Model\FieldType\Select()));
        $this->assertFalse(SuggestionMatcher::appliesTo(new \Furan\FormBuilder\Model\FieldType\Text()));
    }

    public function testItRefusesAnAnswerThatIsNotText(): void
    {
        $this->assertNull((new Suggest())->normalizeValue(['x'], ['options' => [['label' => 'X', 'value' => 'x']]]));
    }
}
