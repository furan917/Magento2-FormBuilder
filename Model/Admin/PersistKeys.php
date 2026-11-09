<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Admin;

/** Data persistor keys shared by an admin Save controller and the Edit block that re-shows a failed save. */
class PersistKeys
{
    public const FORM = 'furan_formbuilder_form';
    public const RULE = 'furan_formbuilder_rule';
    public const FIELD_GROUP = 'furan_formbuilder_field_group';
    private const WEBHOOK_PREFIX = 'furan_formbuilder_webhook_';

    /** One key per endpoint, so a failed save of one never pre-fills another. */
    public static function webhook(?int $endpointId): string
    {
        return self::WEBHOOK_PREFIX . ($endpointId ?? 'new');
    }
}
