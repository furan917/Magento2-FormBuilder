<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Magento\Framework\App\ResourceConnection;

/** Plain-text notices shown above the analytics charts. */
class AnalyticsNotices
{
    private const PENDING_CAP = 10001;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return array<int, \Magento\Framework\Phrase> */
    public function get(int $formId, int $pending): array
    {
        $state = $this->resource->getConnection()->fetchRow(
            'SELECT needs_rebuild, rebuilding, rebuild_started_at FROM '
            . $this->resource->getTableName('furan_formbuilder_stat_state') . ' WHERE form_id = ?',
            [$formId]
        ) ?: [];
        $notices = [];
        if ((int)($state['needs_rebuild'] ?? 0) === 1) {
            $notices[] = __('Statistics do not include submissions made before analytics was installed.');
        }
        if ((int)($state['rebuilding'] ?? 0) === 1) {
            $notices[] = __('A statistics rebuild has been running since %1.', (string)$state['rebuild_started_at']);
        }
        if ($pending > 0) {
            $count = $pending >= self::PENDING_CAP ? '10,000+' : number_format($pending);
            $notices[] = __('%1 updates still being processed.', $count);
        }
        return $notices;
    }
}
