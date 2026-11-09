<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Cron\PrivacyPending;
use Furan\FormBuilder\Model\Privacy\PendingProcessor;
use PHPUnit\Framework\TestCase;

class PrivacyPendingTest extends TestCase
{
    public function testItDrainsDuePendingPrivacyWorkWithinItsTimeBudget(): void
    {
        $processor = $this->createMock(PendingProcessor::class);
        $processor->expects($this->once())->method('drain')->with(PrivacyPending::BUDGET_SECONDS);

        (new PrivacyPending($processor))->execute();
        $this->assertLessThan(300, PrivacyPending::BUDGET_SECONDS, 'shorter than the five minute schedule');
    }
}
