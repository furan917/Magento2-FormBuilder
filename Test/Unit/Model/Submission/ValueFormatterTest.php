<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Model\FieldType\Map;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class ValueFormatterTest extends TestCase
{
    public function testItFormatsAnAddressAsOneReadableLine(): void
    {
        $text = (new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()))->toText(['type' => 'address'], [
            'street' => ['1 High Street', 'Flat 2'],
            'city' => 'Leeds',
            'region_id' => '12',
            'region' => 'West Yorkshire',
            'postcode' => 'LS1 4AB',
            'country_id' => 'GB',
        ], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertStringContainsString('1 High Street, Flat 2, Leeds, West Yorkshire, LS1 4AB', $text);
        $this->assertStringNotContainsString('12', $text);
        $this->assertStringNotContainsString('Array', $text);
        $this->assertStringContainsString('United Kingdom', $text);
        $this->assertStringNotContainsString("\n", $text);
    }

    public function testItMapsOptionValuesToLabelsExceptForPairs(): void
    {
        $formatter = new ValueFormatter(FieldPools::builtIn(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $options = [['value' => 'sales', 'label' => 'Sales team'], ['value' => 'opt_3', 'label' => 'Blue']];

        $this->assertSame(['sales' => 'Sales team', 'opt_3' => 'Blue'], $formatter->optionLabels(['type' => 'select', 'options' => $options]));
        $this->assertSame(['sales' => 'Sales team', 'opt_3' => 'Blue'], $formatter->optionLabels(['type' => 'suggest', 'options' => $options]));
        $this->assertSame([], $formatter->optionLabels(['type' => 'matrix', 'options' => $options]));
        $labels = $formatter->optionLabels(['type' => 'checkboxes', 'options' => $options]);
        $this->assertSame(['Blue', 'gone'], ValueFormatter::withLabels($labels, ['opt_3', 'gone']));
        $this->assertSame('Sales team', ValueFormatter::withLabels($labels, 'sales'));
        $this->assertSame('Sales team', $formatter->toText(['type' => 'select', 'options' => $options], 'sales', \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItShowsFileSizesOfAtLeastOneKilobyte(): void
    {
        $this->assertSame('1 KB', ValueFormatter::fileSize(12));
        $this->assertSame('2 KB', ValueFormatter::fileSize(2048));
        $this->assertSame('1.5 MB', ValueFormatter::fileSize(1572864));
    }

    public function testItFormatsADateRangeAsStartToEndInTheGivenLocale(): void
    {
        $formatter = new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $range = ['start' => '2026-01-02', 'end' => '2026-01-09'];

        $this->assertSame("2 Jan 2026 \u{2013} 9 Jan 2026", $formatter->toText(['type' => 'date_range'], $range, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame("2 janv. 2026 \u{2013} 9 janv. 2026", $formatter->toText(['type' => 'date_range'], $range, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame("2026-01-02 \u{2013} ", $formatter->toText(['type' => 'date_range'], ['start' => '2026-01-02'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
    }

    public function testItNamesTheAddressCountryInTheGivenLocale(): void
    {
        $formatter = new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $address = ['street' => ['1 rue de la Paix'], 'city' => 'Londres', 'postcode' => 'W1 1AA', 'country_id' => 'GB'];

        $this->assertSame('1 rue de la Paix, Londres, W1 1AA, Royaume-Uni', $formatter->toText(['type' => 'address'], $address, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame('1 rue de la Paix, Londres, W1 1AA, Vereinigtes Königreich', $formatter->toText(['type' => 'address'], $address, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'de_DE')));
        $countries = new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $this->assertSame('Royaume-Uni', $countries->toText(['type' => 'country'], 'GB', \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $this->assertSame('<b>', $countries->toText(['type' => 'country'], '<b>', \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
    }

    public function testItKeepsTheAddressCsvCellInEnglishWhateverTheLocale(): void
    {
        $address = ['street' => ['1 rue de la Paix'], 'city' => 'Paris', 'country_id' => 'FR'];
        $formatter = new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate());

        $this->assertSame(['1 rue de la Paix, Paris, France'], $formatter->csvCells(['type' => 'address'], $address));
        $this->assertSame(['1 rue de la Paix, Paris, Germany'], $formatter->csvCells(['type' => 'address'], ['country_id' => 'DE'] + $address));
    }

    public function testItBuildsMapLinksOnlyFromNormalisedCoordinates(): void
    {
        $links = Map::links(['lat' => '-0.127800', 'lng' => '51.500000', 'zoom' => '12']);
        $this->assertSame('https://www.openstreetmap.org/?mlat=-0.127800&mlon=51.500000#map=12/-0.127800/51.500000', $links['osm']);
        $this->assertSame('https://www.google.com/maps/search/?api=1&query=-0.127800,51.500000', $links['google']);
        $this->assertNull(Map::links(['lat' => '1<script>', 'lng' => '2.000000']));
        $this->assertNull(Map::links('x'));
    }

    public function testItUsesTheFieldTypesTextAndHtmlDisplay(): void
    {
        $formatter = new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $field = ['name' => 'p', 'label' => 'Spot', 'type' => 'point'];
        $value = ['x' => '<1>', 'y' => '2'];

        $this->assertSame('P[<1>|2]', $formatter->toText($field, $value, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame('<b>P[&lt;1&gt;|2]</b>', $formatter->toHtml($field, $value, EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItEscapesValuesInTheGenericHtmlFallback(): void
    {
        $formatter = new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $field = ['name' => 'a', 'label' => 'A', 'type' => 'text'];

        $this->assertSame(
            '&lt;script&gt;x&lt;/script&gt;, &quot;y&quot;',
            $formatter->toHtml($field, ['<script>x</script>', '"y"'], EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'))
        );
        $this->assertSame('', $formatter->toHtml(['type' => 'gone'], ['nested' => ['<b>']], EscaperFactory::create(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItProducesTheSameCsvAndDisplayOutputAsBeforeForEveryBuiltInType(): void
    {
        $formatter = new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $map = ['lat' => '-0.127800', 'lng' => '51.500000', 'zoom' => 12, 'label' => ' Home '];
        $address = ['street' => ['1 High Street'], 'city' => 'Leeds', 'region' => 'Yorks', 'postcode' => 'LS1', 'country_id' => 'GB'];

        $this->assertSame('Home (-0.127800, 51.500000)', $formatter->toText(['type' => 'map'], $map, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame('', $formatter->toText(['type' => 'map'], ['lat' => 'x', 'lng' => 'y'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['L (latitude)', 'L (longitude)', 'L (label)'], $formatter->csvHeaders(['label' => 'L', 'type' => 'map']));
        $this->assertSame(['-0.127800', '51.500000', ' Home '], $formatter->csvCells(['type' => 'map'], $map));
        $this->assertSame(['', '', ''], $formatter->csvCells(['type' => 'map'], 'junk'));
        $this->assertSame([0, 1], $formatter->csvPlainColumns(['type' => 'map']));
        $this->assertSame(['L (start)', 'L (end)'], $formatter->csvHeaders(['label' => 'L', 'type' => 'date_range']));
        $this->assertSame(['a', ''], $formatter->csvCells(['type' => 'date_range'], ['start' => 'a']));
        $this->assertSame(['L'], $formatter->csvHeaders(['label' => 'L', 'type' => 'address']));
        $this->assertSame(['1 High Street, Leeds, Yorks, LS1, United Kingdom'], $formatter->csvCells(['type' => 'address'], $address));
        $this->assertSame(['one, two'], $formatter->csvCells(['type' => 'checkboxes'], ['one', 'two']));
        $this->assertSame(['f.pdf'], $formatter->csvCells(['type' => 'file'], ['file' => 'x', 'name' => 'f.pdf']));
        $this->assertSame([], $formatter->csvPlainColumns(['type' => 'text']));
    }

    public function testItKeepsNotificationEmailsAndCsvCellsUnchangedForMatrixAndListAnswers(): void
    {
        $formatter = new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate());
        $matrix = ['name' => 'm', 'label' => 'M', 'type' => 'matrix'];
        $list = ['name' => 'l', 'label' => 'L', 'type' => 'list'];

        $this->assertSame('Speed: Very, Price: Low', $formatter->toText($matrix, ['Speed: Very', 'Price: Low'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['Speed: Very, Price: Low'], $formatter->csvCells($matrix, ['Speed: Very', 'Price: Low']));
        $this->assertSame('one, two', $formatter->toText($list, ['one', 'two'], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(['one, two'], $formatter->csvCells($list, ['one', 'two']));
        $this->assertFalse($formatter->isDisplayable($matrix));
        $this->assertFalse($formatter->isDisplayable($list));
    }

    public function testItLeavesEmptyPartsOutOfAFlattenedAnswer(): void
    {
        $this->assertSame('a, b', ValueFormatter::flatten(['a', '', ['x'], ' ', 'b']));
        $this->assertSame(['a, b'], (new ValueFormatter(FieldPools::adminView(), new \Furan\FormBuilder\Model\Submission\AnswerDate()))->csvCells(['type' => 'gone'], ['a', '', 'b']));
    }
}
