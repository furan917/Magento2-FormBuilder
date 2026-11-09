<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Test\Unit\Helper\AdminViewKindType;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use PHPUnit\Framework\TestCase;

class AnswerViewTest extends TestCase
{
    private function schema(): array
    {
        return ['pages' => [
            ['id' => 'p1', 'title' => 'You', 'items' => [
                ['kind' => 'content', 'type' => 'heading', 'text' => 'Hi'],
                ['kind' => 'field', 'type' => 'text', 'name' => 'name'],
                ['kind' => 'section', 'title' => 'Prefs', 'items' => [
                    ['kind' => 'field', 'type' => 'select', 'name' => 'size', 'options' => [['value' => 'm', 'label' => 'Medium']]],
                ]],
                ['kind' => 'field', 'type' => 'number', 'name' => 'guests', 'settings' => ['prefix' => '', 'suffix' => 'people']],
            ]],
            ['id' => 'p2', 'title' => '', 'items' => [
                ['kind' => 'field', 'type' => 'text', 'name' => 'skipped'],
            ]],
        ]];
    }

    public function testItTreatsAnyServerValueTypeAsServerOwnedInAnEntry(): void
    {
        $pool = new \Furan\FormBuilder\Model\FieldType\Pool(FieldPools::withFiles()->all() + [
            \Furan\FormBuilder\Test\Unit\Helper\StampType::CODE => new \Furan\FormBuilder\Test\Unit\Helper\StampType(),
        ]);
        $view = new AnswerView(new ValueFormatter($pool), $pool);

        $this->assertTrue($view->isServerValue(['type' => 'stamp']));
        $this->assertTrue($view->isServerValue(['type' => 'hidden']));
        $this->assertTrue($view->isServerValue(['type' => 'text', 'field' => ['type' => 'stamp']]));
        $this->assertFalse($view->isServerValue(['type' => 'text', 'field' => ['type' => 'text']]));
        $this->assertFalse($view->isServerValue(['type' => 'text', 'field' => null]));
    }

    public function testItGroupsAnswersByPageAndSectionInFormOrder(): void
    {
        $payload = [
            ['name' => 'guests', 'label' => 'Guests', 'type' => 'number', 'value' => '2'],
            ['name' => 'size', 'label' => 'Size', 'type' => 'select', 'value' => 'm'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Ada'],
            ['name' => 'old', 'label' => 'Old field', 'type' => 'text', 'value' => 'x'],
        ];
        $groups = (new AnswerView(new ValueFormatter(FieldPools::withFiles()), FieldPools::withFiles()))->group($this->schema(), $payload);

        $this->assertCount(2, $groups);
        $this->assertSame('You', $groups[0]['title']);
        $this->assertFalse($groups[0]['removed']);
        $shape = array_map(
            static fn (array $s) => [$s['title'], array_column($s['entries'], 'name')],
            $groups[0]['sections']
        );
        $this->assertSame([['', ['name']], ['Prefs', ['size']], ['', ['guests']]], $shape);
        $this->assertSame('people', $groups[0]['sections'][2]['entries'][0]['field']['settings']['suffix']);
        $this->assertTrue($groups[1]['removed']);
        $this->assertSame(['old'], array_column($groups[1]['sections'][0]['entries'], 'name'));
        $this->assertNull($groups[1]['sections'][0]['entries'][0]['field']);
    }

    public function testItFormatsValuesForDisplay(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::adminView()), FieldPools::adminView());
        $select = ['type' => 'select', 'value' => 'm', 'field' => ['options' => [['value' => 'm', 'label' => 'Medium']]]];
        $this->assertSame(['Medium'], $view->values($select));
        $this->assertSame(['gone'], $view->values(['value' => 'gone'] + $select));
        $this->assertSame(['£1500'], $view->values(['type' => 'text', 'value' => '1500', 'field' => ['settings' => ['prefix' => '£']]]));
        $this->assertSame(['2 people'], $view->values(['type' => 'number', 'value' => '2', 'field' => ['settings' => ['suffix' => 'people']]]));
        $this->assertSame(['Yes'], $view->values(['type' => 'toggle', 'value' => 'yes']));
        $this->assertSame(['No'], $view->values(['type' => 'consent', 'value' => '']));
        $this->assertSame(['United Kingdom'], $view->values(['type' => 'country', 'value' => 'GB']));
        $this->assertSame(['<b>'], $view->values(['type' => 'country', 'value' => '<b>']));
        $matrix = ['type' => 'matrix', 'value' => ['m: Very'], 'field' => ['options' => [['value' => 'm: Very', 'label' => 'x']]]];
        $this->assertSame(['m: Very'], $view->values($matrix));
        $this->assertSame(['a', 'b'], $view->values(['type' => 'checkboxes', 'value' => ['a', '', ['x'], 'b']]));
    }

    public function testItTellsAnsweredFromEmpty(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::adminView()), FieldPools::adminView());
        $this->assertFalse($view->isAnswered(['type' => 'text', 'value' => '']));
        $this->assertFalse($view->isAnswered(['type' => 'checkboxes', 'value' => []]));
        $this->assertTrue($view->isAnswered(['type' => 'range', 'value' => '0']));
        $this->assertTrue($view->isAnswered(['type' => 'toggle', 'value' => '']));
        $this->assertFalse($view->isAnswered(['type' => 'file', 'value' => '']));
        $this->assertTrue($view->isAnswered(['type' => 'file', 'value' => ['name' => 'a.pdf']]));
    }

    public function testItShowsAFormattedAddressInTheSubmissionView(): void
    {
        $values = (new AnswerView(new ValueFormatter(FieldPools::withFiles()), FieldPools::withFiles()))->values(['type' => 'address', 'value' => [
            'street' => ['1 High Street'], 'city' => 'Leeds', 'region_id' => '', 'region' => 'Yorkshire',
            'postcode' => 'LS1', 'country_id' => 'GB',
        ]]);

        $this->assertCount(1, $values);
        $this->assertStringStartsWith('1 High Street, Leeds, Yorkshire, LS1', $values[0]);
        $this->assertTrue((new AnswerView(new ValueFormatter(FieldPools::withFiles()), FieldPools::withFiles()))->isAnswered(['type' => 'address', 'value' => ['street' => ['x']]]));
    }

    public function testItLinksMapAnswersToOpenStreetMapAndGoogleMapsInTheSubmissionView(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::withFiles()), FieldPools::withFiles());
        $entry = ['type' => 'map', 'value' => ['lat' => '51.500000', 'lng' => '-0.127800', 'zoom' => '', 'label' => 'Home']];
        $html = $view->html($entry, EscaperFactory::create());

        $this->assertStringContainsString('openstreetmap.org', $html);
        $this->assertStringContainsString('51.500000,-0.127800', $html);
        $this->assertSame(['Home (51.500000, -0.127800)'], $view->values($entry));
        $bad = ['type' => 'map', 'value' => ['lat' => 'x', 'lng' => '1', 'label' => '']];
        $this->assertStringNotContainsString('openstreetmap', (string)$view->html($bad, EscaperFactory::create()));
    }

    public function testItUsesTheFieldTypesTextAndHtmlDisplayInTheAdminView(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::withFiles()), FieldPools::withFiles());
        $entry = ['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '1', 'y' => '2'], 'field' => null];

        $this->assertSame(['P[1|2]'], $view->values($entry));
        $this->assertSame('<b>P[1|2]</b>', $view->html($entry, EscaperFactory::create()));
        $this->assertNull($view->html(['type' => 'text', 'value' => 'x'], EscaperFactory::create()));
        $this->assertNull($view->html(['type' => 'gone', 'value' => 'x'], EscaperFactory::create()));
    }

    public function testItRendersTheAdminAnswerViewForBuiltInTypesWithoutTypeCodeChecks(): void
    {
        $pool = FieldPools::adminView([
            'tick' => new AdminViewKindType('tick', AdminViewTypeInterface::KIND_BOOLEAN),
            'nation' => new AdminViewKindType('nation', AdminViewTypeInterface::KIND_COUNTRY),
            'typed' => new AdminViewKindType('typed', AdminViewTypeInterface::KIND_FREE_TEXT),
            'plain' => new AdminViewKindType('plain', null),
        ]);
        $view = new AnswerView(new ValueFormatter($pool), $pool);
        $options = ['options' => [['value' => 'a', 'label' => 'Label A']]];

        $this->assertSame(['Yes'], $view->values(['type' => 'tick', 'value' => 'yes']));
        $this->assertSame(['No'], $view->values(['type' => 'tick', 'value' => '']));
        $this->assertTrue($view->isAnswered(['type' => 'tick', 'value' => '']));
        $this->assertSame(['United Kingdom'], $view->values(['type' => 'nation', 'value' => 'GB']));
        $this->assertSame(['a'], $view->values(['type' => 'typed', 'value' => 'a', 'field' => $options]));
        $this->assertSame(['Label A'], $view->values(['type' => 'plain', 'value' => 'a', 'field' => $options]));
        $this->assertSame('boolean', $view->kind(['type' => 'tick']));
        $this->assertNull($view->kind(['type' => 'plain']));
        $this->assertNull($view->kind(['type' => 'gone']));
        $this->assertNull($view->kind(['type' => 'text']));
    }

    public function testItKeepsTypeCodeLiteralsOutOfTheAdminAnswerView(): void
    {
        $sources = [
            (string)file_get_contents(__DIR__ . '/../../../../Model/Submission/AnswerView.php'),
            (string)file_get_contents(__DIR__ . '/../../../../view/adminhtml/templates/submission/view.phtml'),
        ];
        $codes = ['checkbox', 'toggle', 'consent', 'suggest', 'matrix', 'country', 'list', 'checkboxes', 'multiselect',
            'rating', 'scale', 'color', 'textarea', 'newsletter'];
        foreach ($sources as $source) {
            foreach ($codes as $code) {
                $this->assertDoesNotMatchRegularExpression("/'" . $code . "'/", $source, $code);
            }
        }
    }

    public function testItShowsTheNewsletterAnswerAsYesOrNoInTheAdminView(): void
    {
        $pool = FieldPools::adminView();
        $view = new AnswerView(new ValueFormatter($pool), $pool);

        $this->assertSame(['Yes'], $view->values(['type' => 'newsletter', 'value' => 'yes']));
        $this->assertSame(['No'], $view->values(['type' => 'newsletter', 'value' => '']));
        $this->assertTrue($view->isAnswered(['type' => 'newsletter', 'value' => '']));
        $this->assertSame('boolean', $view->kind(['type' => 'newsletter']));
    }
}
