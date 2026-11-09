<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\AuthorizationInterface;

/** An endpoint is a continuous export: changing one, or reading what it received back, needs the export permission. */
class WebhookAccess
{
    public const VIEW = 'Furan_FormBuilder::webhooks';
    public const MANAGE = 'Furan_FormBuilder::webhooks_manage';
    public const EXPORT = 'Furan_FormBuilder::submissions_export';
    public const PERSONAL = 'Furan_FormBuilder::webhooks_personal';

    public static function canChange(AuthorizationInterface $authorization, string $resource = self::MANAGE): bool
    {
        return $authorization->isAllowed($resource)
            && $authorization->isAllowed(self::VIEW)
            && $authorization->isAllowed(self::EXPORT);
    }

    public static function canReadResponses(AuthorizationInterface $authorization, EndpointView $endpoint): bool
    {
        return self::canChange($authorization)
            && (!$endpoint->includePersonal() || $authorization->isAllowed(self::PERSONAL));
    }
}
