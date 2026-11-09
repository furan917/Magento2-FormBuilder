<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Appends statistics deltas for submission changes. Callers own the transaction (and the row lock for
 * replace/remove). Dimensions always come from the stored row, never from the current request.
 *
 * @phpstan-import-type Delta from DeltaCalculator
 * @phpstan-import-type Dims from DeltaCalculator
 * @phpstan-import-type Spec from DeltaCalculator
 */
class Journal
{
    private const TIMEZONE_PATH = 'general/locale/timezone';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeltaCalculator $calculator,
        private readonly SeriesRegistry $seriesRegistry,
        private readonly Json $json,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /** @param array<string, Spec> $specs from SeriesRegistry::specsFor(), resolved before the transaction */
    public function add(SubmissionInterface $submission, array $specs): void
    {
        $this->write(
            $submission->getFormId(),
            $this->calculator->compute($submission->getPayload(), $this->dims($submission), $specs, 1)
        );
    }

    /**
     * @param array<int|string, mixed> $newPayload
     * @param array<string, Spec>|null $specs pass when replacing many rows of one form
     */
    public function replace(SubmissionInterface $stored, array $newPayload, ?array $specs = null): void
    {
        $dims = $this->dims($stored);
        $specs ??= $this->seriesRegistry->load($stored->getFormId());
        $this->write($stored->getFormId(), array_merge(
            $this->calculator->compute($stored->getPayload(), $dims, $specs, -1),
            $this->calculator->compute($newPayload, $dims, $specs, 1)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows raw stored rows: form_id, payload_json, created_at (UTC), store_id, customer_group_id
     */
    public function remove(array $rows): void
    {
        $byForm = [];
        $specs = [];
        foreach ($rows as $row) {
            $formId = (int)$row['form_id'];
            $specs[$formId] ??= $this->seriesRegistry->load($formId);
            $payload = $this->json->unserialize((string)$row['payload_json']);
            $dims = $this->dimsFor((int)($row['store_id'] ?? 0), (int)$row['customer_group_id'], (string)$row['created_at']);
            $byForm[$formId] ??= [];
            array_push(
                $byForm[$formId],
                ...$this->calculator->compute(is_array($payload) ? $payload : [], $dims, $specs[$formId], -1)
            );
        }
        foreach ($byForm as $formId => $deltas) {
            $this->write($formId, $deltas);
        }
    }

    /** @return Dims */
    private function dims(SubmissionInterface $submission): array
    {
        return $this->dimsFor(
            (int)$submission->getStoreId(),
            $submission->getCustomerGroupId(),
            (string)$submission->getCreatedAt()
        );
    }

    /** @return Dims */
    private function dimsFor(int $storeId, int $groupId, string $createdAt): array
    {
        $timezone = $storeId > 0
            ? $this->scopeConfig->getValue(self::TIMEZONE_PATH, ScopeInterface::SCOPE_STORE, $storeId)
            : $this->scopeConfig->getValue(self::TIMEZONE_PATH);

        return [
            'store_id' => $storeId,
            'customer_group_id' => $groupId,
            'created_at' => $createdAt,
            'timezone' => is_string($timezone) && $timezone !== '' ? $timezone : 'UTC',
        ];
    }

    /**
     * Sums identical delta keys and drops cancelled ones; writes nothing when no delta is left.
     *
     * @param list<Delta> $deltas
     */
    private function write(int $formId, array $deltas): void
    {
        $merged = [];
        foreach ($deltas as $delta) {
            $key = implode('|', [$delta['k'], $delta['v'] ?? '', $delta['v'] === null ? 'n' : 'v',
                $delta['p'], $delta['s'], $delta['g']]);
            if (!isset($merged[$key])) {
                $merged[$key] = $delta;
                continue;
            }
            foreach (['h', 'n', 't'] as $measure) {
                $merged[$key][$measure] += $delta[$measure];
            }
            $merged[$key]['l'] ??= $delta['l'];
        }
        $merged = array_values(array_filter(
            $merged,
            static fn (array $d): bool => $d['h'] !== 0 || $d['n'] !== 0 || (float)$d['t'] !== 0.0
        ));
        if ($merged === []) {
            return;
        }
        $this->resource->getConnection()->insert(
            $this->resource->getTableName('furan_formbuilder_stat_journal'),
            ['form_id' => $formId, 'deltas' => $this->json->serialize(['d' => $merged])]
        );
    }
}
