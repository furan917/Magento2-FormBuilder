<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Action\RetryDrainer;

class ActionRetries
{
    public function __construct(private readonly RetryDrainer $drainer)
    {
    }

    public function execute(): void
    {
        $this->drainer->drain();
    }
}
