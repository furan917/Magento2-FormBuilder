<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Form\ScheduleCachePurger;

class FormSchedule
{
    private const BUDGET_SECONDS = 50;

    public function __construct(private readonly ScheduleCachePurger $purger)
    {
    }

    public function execute(): void
    {
        $this->purger->run(self::BUDGET_SECONDS);
    }
}
