<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Source;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use PHPUnit\Framework\TestCase;

class SubmissionStatusTest extends TestCase
{
    public function testItListsEveryStatusAndValidatesValues(): void
    {
        $source = new SubmissionStatus();
        $values = array_column($source->toOptionArray(), 'value');
        $this->assertSame([
            SubmissionInterface::STATUS_NEW,
            SubmissionInterface::STATUS_IN_PROGRESS,
            SubmissionInterface::STATUS_ANSWERED,
            SubmissionInterface::STATUS_CLOSED,
        ], $values);
        $this->assertTrue($source->isValid(3));
        $this->assertFalse($source->isValid(4));
        $this->assertSame('Closed', (string)$source->getLabel(3));
    }
}
