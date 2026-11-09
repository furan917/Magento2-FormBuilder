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
            '1 Main St, Flat 2, Leeds, 5 · a, b · <b>L</b>',
            (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles())))->build($fields, $values)
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
            (new SummaryBuilder($pool, new \Furan\FormBuilder\Model\Submission\ValueFormatter($pool)))->build($fields, $values)
        );
    }

    public function testItCapsTheSummaryAt255Characters(): void
    {
        $out = (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles())))->build([['name' => 'a', 'type' => 'text']], ['a' => str_repeat('é', 300)]);

        $this->assertSame(255, mb_strlen($out));
    }

    public function testItLeavesCustomFileTypesOutOfTheSummary(): void
    {
        $out = (new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles())))->build(
            [['name' => 'cv', 'type' => 'doc'], ['name' => 'a', 'type' => 'text']],
            ['cv' => 'resume.pdf', 'a' => 'Hello']
        );

        $this->assertSame('Hello', $out);
    }
}
