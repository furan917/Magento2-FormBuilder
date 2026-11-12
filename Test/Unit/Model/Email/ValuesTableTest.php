<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Email;

use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Model\Email\ValuesTable;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class ValuesTableTest extends TestCase
{
    private ValuesTable $table;

    protected function setUp(): void
    {
        $pool = FieldPools::withFiles();
        $this->table = new ValuesTable(EscaperFactory::create(), new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool));
    }

    public function testItWritesDatesAndCountriesInTheGivenLocale(): void
    {
        $pool = FieldPools::adminView();
        $table = new ValuesTable(EscaperFactory::create(), new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool));
        $payload = [
            ['name' => 'when', 'label' => 'When', 'type' => 'datetime', 'value' => '2026-10-05T09:15'],
            ['name' => 'stay', 'label' => 'Stay', 'type' => 'date_range', 'value' => ['start' => '2026-10-02', 'end' => '2026-10-04']],
            ['name' => 'from', 'label' => 'From', 'type' => 'country', 'value' => 'GB'],
            ['name' => 'home', 'label' => 'Home', 'type' => 'address', 'value' => ['street' => ['1 rue <b>'], 'city' => 'Lyon', 'country_id' => 'GB']],
        ];

        $this->assertSame([
            ['label' => 'When', 'value' => '5 oct. 2026, 09:15'],
            ['label' => 'Stay', 'value' => "2 oct. 2026 \u{2013} 4 oct. 2026"],
            ['label' => 'From', 'value' => 'Royaume-Uni'],
            ['label' => 'Home', 'value' => '1 rue <b>, Lyon, Royaume-Uni'],
        ], $table->toList($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'fr_FR')));
        $html = $table->toHtml($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'de_DE'));
        $this->assertStringContainsString('<td valign="top" style="padding:4px 0">05.10.2026, 09:15</td>', $html);
        $this->assertStringContainsString('1 rue &lt;b&gt;, Lyon, Vereinigtes Königreich', $html);
        $this->assertStringNotContainsString('2026-10-05T09:15', $html);
    }

    public function testItEscapesLabelsAndValuesInTheValuesTable(): void
    {
        $html = $this->table->toHtml([
            ['name' => 'a', 'label' => '<b>Name</b>', 'type' => 'text', 'value' => "<script>alert(1)</script>\nline2"],
            ['name' => 'b', 'label' => 'Tags', 'type' => 'checkboxes', 'value' => ['x', '<y>']],
            ['name' => 'c', 'label' => 'CV', 'type' => 'file',
                'value' => ['file' => 'furan_formbuilder/1/a.pdf', 'name' => '<cv>.pdf', 'size' => 2048, 'mime' => 'application/pdf']],
        ], [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertStringStartsWith('<table', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br />line2', $html);
        $this->assertStringContainsString('x, &lt;y&gt;', $html);
        $this->assertStringContainsString('&lt;cv&gt;.pdf', $html);
        $this->assertStringNotContainsString('furan_formbuilder/1', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testItPassesAValuesListOfLabelsAndTextValues(): void
    {
        $list = $this->table->toList([
            ['name' => 'a', 'label' => 'Name', 'type' => 'text', 'value' => 'Bob <b>'],
            ['name' => 'b', 'label' => 'Tags', 'type' => 'checkboxes', 'value' => ['x', 'y']],
        ], [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertSame([
            ['label' => 'Name', 'value' => 'Bob <b>'],
            ['label' => 'Tags', 'value' => 'x, y'],
        ], $list);
    }

    public function testItBraceEncodesEveryStructuredTemplateValue(): void
    {
        $list = $this->table->toList([
            ['name' => 'a', 'label' => '{{var x}}', 'type' => 'text', 'value' => '{{config path="a/b"}}'],
        ], [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertSame([['label' => '&#123;&#123;var x&#125;&#125;', 'value' => '&#123;&#123;config path="a/b"&#125;&#125;']], $list);
    }

    public function testItKeepsTemplateDirectivesInSubmittedValuesInert(): void
    {
        $html = $this->table->toHtml([
            ['name' => 'a', 'label' => '{{config path="x"}}', 'type' => 'text', 'value' => '{{var this.getTemplateFilter()}}'],
        ], [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    public function testItEncodesTemplateBracesInTheValuesTable(): void
    {
        $html = $this->table->toHtml([['name' => 'a', 'label' => 'L{', 'type' => 'text', 'value' => '{v}']], [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertStringContainsString('L&#123;', $html);
        $this->assertStringContainsString('&#123;v&#125;', $html);
        $this->assertStringNotContainsString('{', $html);
    }

    public function testItRendersUnstoredUploadsByNameAndSizeInEmails(): void
    {
        $payload = [['name' => 'cv', 'label' => 'CV', 'type' => 'file',
            'value' => ['name' => 'cv.pdf', 'size' => 2048, 'mime' => 'application/pdf']]];

        $html = $this->table->toHtml($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));
        $this->assertStringContainsString('cv.pdf (2 KB)', $html);
        $this->assertStringNotContainsString('application/pdf', $html);
        $this->assertSame([['label' => 'CV', 'value' => 'cv.pdf (2 KB)']], $this->table->toList($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItListsStructuredAnswersInTheNotificationEmailWithoutArrayToStringConversion(): void
    {
        $payload = [
            ['name' => 'a', 'label' => 'Addr', 'type' => 'address', 'value' => [
                'street' => ['1 <High> St'], 'city' => 'Leeds', 'region_id' => '', 'region' => '', 'postcode' => 'LS1', 'country_id' => 'GB',
            ]],
            ['name' => 'd', 'label' => 'Stay', 'type' => 'date_range', 'value' => ['start' => '2026-01-02', 'end' => '2026-01-09']],
            ['name' => 'm', 'label' => 'Where', 'type' => 'map', 'value' => ['lat' => '51.500000', 'lng' => '-0.127800', 'zoom' => '', 'label' => 'Home']],
        ];
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg);
        });
        try {
            $html = $this->table->toHtml($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));
            $text = implode("\n", array_column($this->table->toList($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')), 'value'));
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('1 &lt;High&gt; St, Leeds, LS1', $html);
        $this->assertStringContainsString("2 Jan 2026 \u{2013} 9 Jan 2026", $html);
        $this->assertStringContainsString('Home (51.500000, -0.127800)', $text);
        $this->assertStringNotContainsString('Array', $html . $text);
    }

    public function testItUsesTheFieldTypesTextInTheEmailValuesTable(): void
    {
        $payload = [['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '<1>', 'y' => '{2}']]];

        $this->assertStringContainsString('P[&lt;1&gt;|&#123;2&#125;]', $this->table->toHtml($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame('P[<1>|&#123;2&#125;]', $this->table->toList($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'))[0]['value']);
    }

    public function testItShowsOptionLabelsFromTheSchemaAndKeepsValuesWhoseOptionIsGone(): void
    {
        $pool = FieldPools::builtIn();
        $table = new ValuesTable(EscaperFactory::create(), new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool));
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'select', 'name' => 'topic', 'options' => [['value' => 'sales', 'label' => 'Ventes']]],
            ['kind' => 'field', 'type' => 'checkboxes', 'name' => 'tags', 'options' => [
                ['value' => 'opt_1', 'label' => 'Rouge'], ['value' => 'opt_3', 'label' => 'Bleu'],
            ]],
        ]]]];
        $payload = [
            ['name' => 'topic', 'label' => 'Topic', 'type' => 'select', 'value' => 'sales'],
            ['name' => 'tags', 'label' => 'Tags', 'type' => 'checkboxes', 'value' => ['opt_3', 'gone']],
            ['name' => 'old', 'label' => 'Old', 'type' => 'radio', 'value' => 'opt_9'],
        ];

        $this->assertSame([
            ['label' => 'Topic', 'value' => 'Ventes'],
            ['label' => 'Tags', 'value' => 'Bleu, gone'],
            ['label' => 'Old', 'value' => 'opt_9'],
        ], $table->toList($payload, $schema, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertStringContainsString('Ventes', $table->toHtml($payload, $schema, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertStringNotContainsString('sales', $table->toHtml($payload, $schema, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }

    public function testItShowsYesOrNoForATickedOrUntickedAnswer(): void
    {
        $pool = FieldPools::builtIn();
        $table = new ValuesTable(EscaperFactory::create(), new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool));
        $list = $table->toList([
            ['name' => 'ok', 'label' => 'OK', 'type' => 'toggle', 'value' => 'yes'],
            ['name' => 'no', 'label' => 'No', 'type' => 'toggle', 'value' => ''],
        ], [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB'));

        $this->assertSame(['Yes', 'No'], array_column($list, 'value'));
    }

    public function testItShowsASmallUploadAsOneKilobyte(): void
    {
        $payload = [['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => ['name' => 'a.txt', 'size' => 12]]];

        $this->assertStringContainsString('a.txt (1 KB)', $this->table->toHtml($payload, [], \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
    }
}
