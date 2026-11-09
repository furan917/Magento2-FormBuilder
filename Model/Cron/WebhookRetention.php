<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Webhook\RetentionPurger;

class WebhookRetention
{
    public function __construct(private readonly RetentionPurger $purger)
    {
    }

    public function execute(): void
    {
        $this->purger->run();
    }
}
