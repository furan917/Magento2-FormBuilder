<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Api\FieldType\StatsLabelInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Stats\Bucket;
use Furan\FormBuilder\Model\Stats\ChartShape;
use Furan\FormBuilder\Model\Stats\Histogram;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use PHPUnit\Framework\TestCase;

class ChartShapeTest extends TestCase
{
    public function testItLabelsChartValuesThroughTheFieldType(): void
    {
        $type = new class extends AbstractFieldType implements StatsLabelInterface {
            /** @var string[] */
            public array $asked = [];

            public function getCode(): string
            {
                return 'nation';
            }

            public function getLabel(): string
            {
                return 'Nation';
            }

            public function statsLabel(string $value, array $field): string
            {
                $this->asked[] = $value;
                return strtoupper($value) . '!';
            }
        };
        $shape = new ChartShape(new Pool(['nation' => $type, 'text' => new Text()]), new Histogram(new Bucket()));
        $hits = [$shape->hex('gb') => 4, $shape->hex('de') => 1];
        $labels = [$shape->hex('gb') => 'gb', $shape->hex('de') => 'de'];

        $nation = $shape->build('where', SeriesSpec::KIND_CHOICE, ['type' => 'nation', 'label' => 'Where'], $hits, null, $labels);
        $plain = $shape->build('where', SeriesSpec::KIND_CHOICE, ['type' => 'text', 'label' => 'Where'], $hits, null, $labels);

        $this->assertSame(['GB!', 'DE!'], array_column($nation['options'], 'label'));
        $this->assertSame(['gb', 'de'], array_column($plain['options'], 'label'));
        $this->assertSame(['gb', 'de'], $type->asked);
    }

    public function testItKeepsHexKeysAndSchemaLabelsForTypesWithoutALabelHook(): void
    {
        $shape = new ChartShape(new Pool(['text' => new Text()]), new Histogram(new Bucket()));
        $field = ['type' => 'gone', 'label' => 'F', 'options' => [['value' => 'a', 'label' => 'Label A']]];

        $chart = $shape->build('f', SeriesSpec::KIND_CHOICE, $field, [$shape->hex('a') => 2, $shape->hex('zz') => 1], null, []);

        $this->assertSame(['Label A', $shape->hex('zz')], array_column($chart['options'], 'label'));
    }

    public function testItBinsHistogramsByTheFrozenStepAndKeepsTheAverage(): void
    {
        $shape = new ChartShape(new Pool(['text' => new Text()]), new Histogram(new Bucket()));
        $hits = [$shape->hex('20') => 2, $shape->hex('0') => 5, $shape->hex('deadbeef') => 1];
        $labels = [$shape->hex('20') => '20', $shape->hex('0') => '0'];

        $chart = $shape->build('qty', SeriesSpec::KIND_NUMBER, ['label' => 'Qty'], $hits, ['n' => 7, 'total' => 70.0], $labels, 10.0);

        $this->assertSame('histogram', $chart['type']);
        $this->assertSame(10.0, $chart['average']);
        $this->assertSame(
            [['label' => '0-9', 'hits' => 5], ['label' => '10-19', 'hits' => 0], ['label' => '20-29', 'hits' => 2]],
            $chart['buckets']
        );
    }

    public function testItOrdersMatrixRowsAndAnswersAsTheSchemaDoes(): void
    {
        $shape = new ChartShape(new Pool(['text' => new Text()]), new Histogram(new Bucket()));
        $field = [
            'type' => 'matrix', 'label' => 'Rate us',
            'settings' => ['rows' => ['Performance', 'Security', 'Hyva themes']],
            'options' => [['value' => 'v', 'label' => 'Very'], ['value' => 'l', 'label' => 'A little'], ['value' => 'n', 'label' => 'Not at all']],
        ];
        $hits = [];
        $labels = [];
        foreach ([
            'Hyva themes: A little' => 1, 'Hyva themes: Not at all' => 2, 'Hyva themes: Very' => 3,
            'Security: Not at all' => 1, 'Security: Very' => 2, 'Security: A little' => 1,
            'Gone: Very' => 1, 'Performance: Old answer' => 5, 'Performance: Very' => 4,
        ] as $text => $count) {
            $hits[$shape->hex($text)] = $count;
            $labels[$shape->hex($text)] = $text;
        }
        $hits[$shape->hex('__answered')] = 9;

        $list = $shape->build('grid', SeriesSpec::KIND_MATRIX, $field, $hits, null, $labels)['rows'];
        $rows = array_column($list, 'options', 'label');

        $this->assertSame(['Performance', 'Security', 'Hyva themes', 'Other (renamed)'], array_column($list, 'label'));
        $this->assertSame(['Very', 'Old answer'], array_column($rows['Performance'], 'label'));
        $this->assertSame(['Very', 'A little', 'Not at all'], array_column($rows['Security'], 'label'));
        $this->assertSame(['Very', 'A little', 'Not at all'], array_column($rows['Hyva themes'], 'label'));
        $this->assertSame([3, 1, 2], array_column($rows['Hyva themes'], 'hits'));
    }

    public function testItKeepsNumericMatrixRowNamesInSchemaOrder(): void
    {
        $shape = new ChartShape(new Pool(['text' => new Text()]), new Histogram(new Bucket()));
        $field = ['type' => 'matrix', 'settings' => ['rows' => ['2', '1']], 'options' => [['value' => 'y', 'label' => 'Yes']]];
        $hits = [$shape->hex('1: Yes') => 1, $shape->hex('2: Yes') => 2];
        $labels = [$shape->hex('1: Yes') => '1: Yes', $shape->hex('2: Yes') => '2: Yes'];

        $rows = $shape->build('grid', SeriesSpec::KIND_MATRIX, $field, $hits, null, $labels)['rows'];

        $this->assertSame(['2', '1'], array_column($rows, 'label'));
        $this->assertSame('[{"label":"2"', substr((string)json_encode($rows), 0, 13));
    }
}
