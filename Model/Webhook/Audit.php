<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;

class Audit
{
    public const TABLE = 'furan_formbuilder_webhook_audit';
    public const ACTIONS = [
        'created', 'url_changed', 'personal_on', 'personal_off', 'debug_on', 'debug_off', 'secret_rotated',
        'enabled', 'disabled', 'deleted', 'auto_disabled', 'test_sent',
    ];
    private const DETAIL_LENGTH = 255;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** The detail is a short note (a host, never a secret or submission data); query strings are cut as a safety net. */
    public function record(int $endpointId, ?int $adminId, string $action, string $detail = ''): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown webhook audit action.');
        }
        $detail = (string)preg_replace('/[?#]\S*/u', '', $detail);
        $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
            'endpoint_id' => $endpointId,
            'admin_user_id' => $adminId,
            'action' => $action,
            'detail' => mb_substr($detail, 0, self::DETAIL_LENGTH),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
