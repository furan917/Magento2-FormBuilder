<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Cron\FormSchedule;
use Furan\FormBuilder\Model\Form\ScheduleCachePurger;
use PHPUnit\Framework\TestCase;

class FormScheduleTest extends TestCase
{
    public function testItRunsThePurgerWithAFiftySecondBudget(): void
    {
        $purger = $this->createMock(ScheduleCachePurger::class);
        $purger->expects($this->once())->method('run')->with(50);

        (new FormSchedule($purger))->execute();
    }
}
