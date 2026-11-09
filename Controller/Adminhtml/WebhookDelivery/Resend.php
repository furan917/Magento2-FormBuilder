<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\WebhookDelivery;

class Resend extends AbstractReplay
{
    protected function newId(): bool
    {
        return true;
    }
}
