<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\Bucket;
use PHPUnit\Framework\TestCase;

class BucketTest extends TestCase
{
    private Bucket $bucket;

    protected function setUp(): void
    {
        $this->bucket = new Bucket();
    }

    public function testItBucketsDateAnswersByTheFrozenDayMonthOrYearStep(): void
    {
        $this->assertSame('2024-03-15', $this->bucket->date('2024-03-15', Bucket::DAY));
        $this->assertSame('2024-03-15', $this->bucket->date('2024-03-15T10:30', Bucket::DAY));
        $this->assertSame('2024-03', $this->bucket->date('2024-03', Bucket::DAY));
        $this->assertSame('2024-03', $this->bucket->date('2024-03-15', 1));
        $this->assertSame('2024-03', $this->bucket->date('2024-03-15T10:30', 1));
        $this->assertSame('2024-03', $this->bucket->date('2024-03', 1));
        $this->assertSame('2024', $this->bucket->date('2024-03-15', 12));
        $this->assertSame('2024', $this->bucket->date('2024-03', 12));
        $this->assertNull($this->bucket->date('not a date', 1));
        $this->assertNull($this->bucket->date('', 12));
    }

    public function testItBucketsNumbersWithTheFrozenSeriesStepAndIgnoresCurrentFieldSettings(): void
    {
        $this->assertSame('30', $this->bucket->number(37, 10.0));
        $this->assertSame('30', $this->bucket->number('37.9', 10.0));
        $this->assertSame('-10', $this->bucket->number(-3, 10.0));
        $this->assertSame('0.3', $this->bucket->number(0.3, 0.1));
        $this->assertSame('0', $this->bucket->number(-0.0, 5.0));
        $this->assertNull($this->bucket->number('abc', 10.0));

    }

    public function testItBucketsNumbersWithoutAStepToTwoSignificantFigures(): void
    {
        $this->assertSame('25', $this->bucket->number(25, null));
        $this->assertSame('80', $this->bucket->number('80.9', null));
        $this->assertSame('1200', $this->bucket->number(1234, null));
        $this->assertSame('1000', $this->bucket->number(1000, null));
        $this->assertSame('99', $this->bucket->number(99.99, null));
        $this->assertSame('1.5', $this->bucket->number(1.57, null));
        $this->assertSame('0.3', $this->bucket->number(0.3, null));
        $this->assertSame('0.037', $this->bucket->number(0.0378, null));
        $this->assertSame('-46', $this->bucket->number(-45.5, null));
        $this->assertSame('-45', $this->bucket->number(-45, null));
        $this->assertSame('0', $this->bucket->number(0, null));
        $this->assertSame('0', $this->bucket->number(-0.0, null));
    }

    public function testItFormatsCanonicalValuesIdenticallyForInMemoryAndDecodedPayloads(): void
    {
        $this->assertSame('5', $this->bucket->format(5));
        $this->assertSame('5', $this->bucket->format('5.000'));
        $this->assertSame('5', $this->bucket->format(5.0));
        $this->assertSame('2.5', $this->bucket->format(2.5));
        $this->assertSame('0', $this->bucket->format(-0.0));
        $this->assertSame('0', $this->bucket->format('-0'));
        $this->assertSame(
            $this->bucket->format(0.1 + 0.2),
            $this->bucket->format((float)json_decode((string)json_encode(0.1 + 0.2), true))
        );
    }
}
