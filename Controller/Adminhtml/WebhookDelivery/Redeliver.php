<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\WebhookDelivery;

class Redeliver extends AbstractReplay
{
    protected function newId(): bool
    {
        return false;
    }
}
