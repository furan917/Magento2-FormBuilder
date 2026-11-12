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
        $view = new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool);

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
        $groups = (new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()))->group($this->schema(), $payload);

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
        $view = new AnswerView(new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::adminView());
        $select = ['type' => 'select', 'value' => 'm', 'field' => ['options' => [['value' => 'm', 'label' => 'Medium']]]];
        $this->assertSame(['Medium'], $view->values($select, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['gone'], $view->values(['value' => 'gone'] + $select, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['£1500'], $view->values(['type' => 'text', 'value' => '1500', 'field' => ['settings' => ['prefix' => '£']]], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['2 people'], $view->values(['type' => 'number', 'value' => '2', 'field' => ['settings' => ['suffix' => 'people']]], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['Yes'], $view->values(['type' => 'toggle', 'value' => 'yes'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['No'], $view->values(['type' => 'consent', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['United Kingdom'], $view->values(['type' => 'country', 'value' => 'GB'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['<b>'], $view->values(['type' => 'country', 'value' => '<b>'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $matrix = ['type' => 'matrix', 'value' => ['m: Very'], 'field' => ['options' => [['value' => 'm: Very', 'label' => 'x']]]];
        $this->assertSame(['m: Very'], $view->values($matrix, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['a', 'b'], $view->values(['type' => 'checkboxes', 'value' => ['a', '', ['x'], 'b']], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItTellsAnsweredFromEmpty(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::adminView());
        $this->assertFalse($view->isAnswered(['type' => 'text', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertFalse($view->isAnswered(['type' => 'checkboxes', 'value' => []], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertTrue($view->isAnswered(['type' => 'range', 'value' => '0'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertFalse($view->isAnswered(['type' => 'toggle', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertTrue($view->isAnswered(['type' => 'toggle', 'value' => 'yes'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertFalse($view->isAnswered(['type' => 'color', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertFalse($view->isAnswered(['type' => 'file', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertTrue($view->isAnswered(['type' => 'file', 'value' => ['name' => 'a.pdf']], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItShowsAFormattedAddressInTheSubmissionView(): void
    {
        $values = (new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()))->values(['type' => 'address', 'value' => [
            'street' => ['1 High Street'], 'city' => 'Leeds', 'region_id' => '', 'region' => 'Yorkshire',
            'postcode' => 'LS1', 'country_id' => 'GB',
        ]], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertCount(1, $values);
        $this->assertStringStartsWith('1 High Street, Leeds, Yorkshire, LS1', $values[0]);
        $this->assertTrue((new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()))->isAnswered(['type' => 'address', 'value' => ['street' => ['x']]], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItLinksMapAnswersToOpenStreetMapAndGoogleMapsInTheSubmissionView(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles());
        $entry = ['type' => 'map', 'value' => ['lat' => '51.500000', 'lng' => '-0.127800', 'zoom' => '', 'label' => 'Home']];
        $html = $view->html($entry, EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertStringContainsString('openstreetmap.org', $html);
        $this->assertStringContainsString('51.500000,-0.127800', $html);
        $this->assertSame(['Home (51.500000, -0.127800)'], $view->values($entry, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $bad = ['type' => 'map', 'value' => ['lat' => 'x', 'lng' => '1', 'label' => '']];
        $this->assertStringNotContainsString('openstreetmap', (string)$view->html($bad, EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItUsesTheFieldTypesTextAndHtmlDisplayInTheAdminView(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles());
        $entry = ['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '1', 'y' => '2'], 'field' => null];

        $this->assertSame(['P[1|2]'], $view->values($entry, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame('<b>P[1|2]</b>', $view->html($entry, EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertNull($view->html(['type' => 'text', 'value' => 'x'], EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertNull($view->html(['type' => 'gone', 'value' => 'x'], EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItRendersTheAdminAnswerViewForBuiltInTypesWithoutTypeCodeChecks(): void
    {
        $pool = FieldPools::adminView([
            'tick' => new AdminViewKindType('tick', AdminViewTypeInterface::KIND_BOOLEAN),
            'nation' => new AdminViewKindType('nation', AdminViewTypeInterface::KIND_COUNTRY),
            'typed' => new AdminViewKindType('typed', AdminViewTypeInterface::KIND_FREE_TEXT),
            'plain' => new AdminViewKindType('plain', null),
        ]);
        $view = new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool);
        $options = ['options' => [['value' => 'a', 'label' => 'Label A']]];

        $this->assertSame(['Yes'], $view->values(['type' => 'tick', 'value' => 'yes'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['No'], $view->values(['type' => 'tick', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertFalse($view->isAnswered(['type' => 'tick', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertTrue($view->isAnswered(['type' => 'tick', 'value' => 'yes'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['United Kingdom'], $view->values(['type' => 'nation', 'value' => 'GB'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['a'], $view->values(['type' => 'typed', 'value' => 'a', 'field' => $options], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['Label A'], $view->values(['type' => 'plain', 'value' => 'a', 'field' => $options], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
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
        $view = new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool);

        $this->assertSame(['Yes'], $view->values(['type' => 'newsletter', 'value' => 'yes'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['No'], $view->values(['type' => 'newsletter', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertFalse($view->isAnswered(['type' => 'newsletter', 'value' => ''], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertTrue($view->isAnswered(['type' => 'newsletter', 'value' => 'yes'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame('boolean', $view->kind(['type' => 'newsletter']));
    }

    public function testItGivesEachBuiltInDateTypeItsDateKind(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::adminView());
        $kinds = [];
        foreach (FieldPools::builtIn()->all() as $code => $type) {
            $kinds[$code] = $view->kind(['type' => $code]);
        }

        $this->assertSame(AdminViewTypeInterface::KIND_CALENDAR_DATE, $kinds['date']);
        $this->assertSame(AdminViewTypeInterface::KIND_CALENDAR_DATETIME, $kinds['datetime']);
        $this->assertSame(AdminViewTypeInterface::KIND_CALENDAR_MONTH, $kinds['month']);
        $this->assertSame(AdminViewTypeInterface::KIND_CALENDAR_TIME, $kinds['time']);
        $this->assertSame(AdminViewTypeInterface::KIND_CALENDAR_RANGE, $kinds['date_range']);
    }

    public function testItTranslatesYesAndNoWithoutUsingThemToDecideWhetherABoxWasTicked(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::adminView());
        $context = \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR');

        [$values, $ticked, $unticked] = \Furan\FormBuilder\Test\Unit\Helper\Translated::with('fr:', static fn (): array => [
            $view->values(['type' => 'toggle', 'value' => 'yes'], $context),
            $view->isAnswered(['type' => 'toggle', 'value' => 'yes'], $context),
            $view->isAnswered(['type' => 'toggle', 'value' => ''], $context),
        ]);

        $this->assertSame(['fr:Yes'], $values);
        $this->assertTrue($ticked);
        $this->assertFalse($unticked);
    }

    public function testItShowsDatesAndCountriesInTheReadersLocale(): void
    {
        $view = new AnswerView(new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::adminView());
        $range = ['type' => 'date_range', 'value' => ['start' => '2026-10-02', 'end' => '2026-10-04']];

        $this->assertSame(['5 oct. 2026, 09:15'], $view->values(['type' => 'datetime', 'value' => '2026-10-05T09:15'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame(['5 Oct 2026, 09:15'], $view->values(['type' => 'datetime', 'value' => '2026-10-05T09:15'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['2 oct. 2026'], $view->values(['type' => 'date', 'value' => '2026-10-02'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame(['septembre 2026'], $view->values(['type' => 'month', 'value' => '2026-09'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame(['21:05'], $view->values(['type' => 'time', 'value' => '21:05'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'de_DE')));
        $this->assertSame(["2 oct. 2026 \u{2013} 4 oct. 2026"], $view->values($range, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame(['Royaume-Uni'], $view->values(['type' => 'country', 'value' => 'GB'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame(['2026-02-30'], $view->values(['type' => 'date', 'value' => '2026-02-30'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
    }

    public function testItTellsBrowserSourcedHiddenAnswersFromServerSetOnes(): void
    {
        $pool = new \Furan\FormBuilder\Model\FieldType\Pool(FieldPools::withFiles()->all() + [
            \Furan\FormBuilder\Test\Unit\Helper\StampType::CODE => new \Furan\FormBuilder\Test\Unit\Helper\StampType(),
        ]);
        $view = new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool);
        $hidden = static fn (array $field): array => ['type' => 'hidden', 'value' => 'x', 'field' => ['type' => 'hidden'] + $field];

        foreach (['query', 'page_url', 'referrer', 'campaign'] as $source) {
            $this->assertTrue($view->isBrowserValue($hidden(['settings' => ['source' => $source]])), $source);
        }
        $this->assertFalse($view->isBrowserValue($hidden(['settings' => ['source' => 'static'], 'default' => 'a'])));
        $this->assertFalse($view->isBrowserValue($hidden(['settings' => []])));
        $this->assertFalse($view->isBrowserValue($hidden(['prefill' => 'customer.email', 'settings' => ['source' => 'query']])));
        $this->assertFalse($view->isBrowserValue(['type' => 'hidden', 'value' => 'x', 'field' => null]), 'removed field: source unknown');
        $this->assertFalse($view->isBrowserValue(['type' => 'hidden', 'value' => 'x', 'field' => ['type' => 'text']]), 'retyped field');
        $this->assertFalse($view->isBrowserValue(['type' => 'stamp', 'value' => 'x', 'field' => ['type' => 'stamp']]));
        $this->assertFalse($view->isBrowserValue(['type' => 'text', 'value' => 'x', 'field' => ['type' => 'text']]));
    }
}
