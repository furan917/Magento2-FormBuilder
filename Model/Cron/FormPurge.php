<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Form\Purger;

class FormPurge
{
    private const BUDGET_SECONDS = 240;

    public function __construct(private readonly Purger $purger)
    {
    }

    public function execute(): void
    {
        $this->purger->run(self::BUDGET_SECONDS);
    }
}
