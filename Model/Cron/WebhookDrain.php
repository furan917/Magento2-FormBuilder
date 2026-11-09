<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Webhook\DeliveryDrainer;

class WebhookDrain
{
    public function __construct(private readonly DeliveryDrainer $drainer)
    {
    }

    public function execute(): void
    {
        $this->drainer->drain();
    }
}
