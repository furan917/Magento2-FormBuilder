<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\SummaryBuilder;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\TestCase;

class SummaryBuilderTest extends TestCase
{
    public function testItFlattensNestedAnswerValuesIntoTheSummary(): void
    {
        $fields = [
            ['name' => 'addr', 'type' => 'address'],
            ['name' => 'src', 'type' => 'hidden'],
            ['name' => 'cv', 'type' => 'file'],
            ['name' => 'tags', 'type' => 'checkboxes'],
            ['name' => 'empty', 'type' => 'text'],
            ['name' => 'last', 'type' => 'text'],
            ['name' => 'extra', 'type' => 'text'],
        ];
        $values = [
            'addr' => ['street' => ['1 Main St', 'Flat 2'], 'city' => 'Leeds', 'n' => 5],
            'src' => 'campaign',
            'cv' => ['file' => 'x'],
            'tags' => ['a', 'b'],
            'empty' => '  ',
            'last' => '<b>L</b>',
            'extra' => 'nope',
        ];

        $this->assertSame(
            '1 Main St, Flat 2, Leeds · a, b · <b>L</b>',
            (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))))->build($fields, $values, 1)
        );
    }

    public function testItSummarisesAStructuredAnswerAsTheTypeShowsItSkippingEmptyParts(): void
    {
        $values = ['addr' => [
            'street' => ['1 High Street', ''], 'city' => 'London', 'region_id' => '', 'region' => ' ',
            'postcode' => 'SW1A 1AA', 'country_id' => 'GB',
        ]];

        $this->assertSame(
            '1 High Street, London, SW1A 1AA, United Kingdom',
            (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))))
                ->build([['name' => 'addr', 'type' => 'address']], $values, 1)
        );
    }

    public function testItShowsOptionLabelsInTheGridSummaryAndKeepsValuesWhoseOptionIsGone(): void
    {
        $fields = [
            ['name' => 'topic', 'type' => 'select', 'options' => [['value' => 'sales', 'label' => 'Sales team']]],
            ['name' => 'tags', 'type' => 'checkboxes', 'options' => [
                ['value' => 'opt_1', 'label' => 'Red'], ['value' => 'opt_3', 'label' => 'Blue'],
            ]],
            ['name' => 'old', 'type' => 'radio', 'options' => [['value' => 'a', 'label' => 'A']]],
        ];
        $values = ['topic' => 'sales', 'tags' => ['opt_3', 'opt_1'], 'old' => 'removed'];
        $pool = FieldPools::builtIn();

        $this->assertSame(
            'Sales team · Blue, Red · removed',
            (new SummaryBuilder($pool, new \Furan\FormBuilder\Model\Submission\ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))))->build($fields, $values, 1)
        );
    }

    public function testItCapsTheSummaryAt255Characters(): void
    {
        $out = (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))))->build([['name' => 'a', 'type' => 'text']], ['a' => str_repeat('é', 300)], 1);

        $this->assertSame(255, mb_strlen($out));
    }

    public function testItLeavesCustomFileTypesOutOfTheSummary(): void
    {
        $out = (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))))->build(
            [['name' => 'cv', 'type' => 'doc'], ['name' => 'a', 'type' => 'text']],
            ['cv' => 'resume.pdf', 'a' => 'Hello']
        , 1);

        $this->assertSame('Hello', $out);
    }

    public function testItUsesTheTypesOwnTextForOtherStructuredAnswers(): void
    {
        $this->assertSame(
            "Dec 20, 2026 \u{2013} Dec 28, 2026",
            (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))))
                ->build([['name' => 'stay', 'type' => 'date_range']], ['stay' => ['start' => '2026-12-20', 'end' => '2026-12-28']], 1)
        );
    }

    public function testItWritesDatesAndCountriesInTheSubmissionsStoreViewLocale(): void
    {
        $config = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $config->expects($this->once())->method('getValue')->with('general/locale/code', 'store', 2)->willReturn('fr_FR');
        $pool = FieldPools::adminView();
        $builder = new SummaryBuilder($pool, new \Furan\FormBuilder\Model\Submission\ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($config));
        $fields = [['name' => 'when', 'type' => 'datetime'], ['name' => 'from', 'type' => 'country'], ['name' => 'home', 'type' => 'address']];
        $values = ['when' => '2026-10-05T09:15', 'from' => 'GB', 'home' => ['city' => 'Lyon', 'country_id' => 'DE']];

        $this->assertSame('5 oct. 2026, 09:15 · Royaume-Uni · Lyon, Allemagne', $builder->build($fields, $values, 2));
    }
}
