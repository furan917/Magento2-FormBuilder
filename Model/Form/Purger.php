<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deleting a form cascades to all of its submissions in one statement; for a large form that is one huge
 * transaction. Large forms are disabled and flagged instead, and the cron drains their rows in small batches.
 */
class Purger
{
    public const LOCK_NAME = 'furanform_form_purge';
    public const INLINE_LIMIT = 5000;
    public const BATCH_SIZE = 500;
    private const STAT_CHUNK = 5000;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly FormRepositoryInterface $formRepository,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        private readonly KeysetIndex $keysetIndex,
        private readonly SubmissionDeliveryCleaner $deliveryCleaner,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function hasSubmissions(int $formId): bool
    {
        return $this->resource->getConnection()->fetchOne(
            'SELECT 1 FROM ' . $this->table('furan_formbuilder_submission') . ' WHERE form_id = ? LIMIT 1',
            [$formId]
        ) !== false;
    }

    /** True when deleting inline would cascade through more than INLINE_LIMIT submissions. */
    public function isLarge(int $formId): bool
    {
        return (int)$this->resource->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->table('furan_formbuilder_submission')
            . ' WHERE form_id = ? LIMIT ' . (self::INLINE_LIMIT + 1) . ') t',
            [$formId]
        ) > self::INLINE_LIMIT;
    }

    /** @return bool true when deleted now, false when left to the background purge */
    public function deleteOrSchedule(FormInterface $form): bool
    {
        if ($this->isLarge((int)$form->getId())) {
            $this->schedule($form);
            return false;
        }
        // Batch the submissions (and their deliveries) away first, so the form-level cascade finds none.
        $this->purgeSubmissions((int)$form->getId(), PHP_FLOAT_MAX);
        $this->formRepository->delete($form);
        return true;
    }

    /** Takes the form off the storefront (a model save, so its cache tags are cleaned) and queues the purge. */
    public function schedule(FormInterface $form): void
    {
        if (!$form instanceof DataObject) {
            throw new \InvalidArgumentException('The form must be a model.');
        }
        $form->setIsActive(false);
        $form->setData('purge_requested_at', gmdate('Y-m-d H:i:s'));
        $this->formRepository->save($form);
    }

    public function run(int $budgetSeconds): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $deadline = ($this->clock)() + $budgetSeconds;
            $formIds = $this->resource->getConnection()->fetchCol(
                'SELECT form_id FROM ' . $this->table('furan_formbuilder_form')
                . ' WHERE purge_requested_at IS NOT NULL ORDER BY purge_requested_at, form_id'
            );
            foreach ($formIds as $formId) {
                if (!$this->purge((int)$formId, $deadline)) {
                    return;
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /** @return bool false when the budget ran out before the form was gone */
    private function purge(int $formId, float $deadline): bool
    {
        if (!$this->purgeSubmissions($formId, $deadline)) {
            return false;
        }
        // Autocommit per chunk; no journal rows: the form's statistics go with it.
        $steps = [
            ['furan_formbuilder_draft', 'form_id = ?', self::STAT_CHUNK],
            ['furan_formbuilder_respondent', 'form_id = ?', self::STAT_CHUNK],
            ['furan_formbuilder_stat_journal', 'form_id = ?', self::STAT_CHUNK],
        ];
        $fieldScope = 'field_id IN (SELECT field_id FROM ' . $this->table('furan_formbuilder_stat_field')
            . ' WHERE form_id = ?)';
        foreach (['stat_value', 'stat_number', 'stat_label'] as $name) {
            $steps[] = ['furan_formbuilder_' . $name, $fieldScope, self::STAT_CHUNK];
        }
        foreach (['stat_day', 'stat_stage_day', 'stat_stage_value', 'stat_stage_number', 'stat_stage_label',
            'stat_stage_journal'] as $name) {
            $steps[] = ['furan_formbuilder_' . $name, 'form_id = ?', self::STAT_CHUNK];
        }
        foreach ($steps as [$table, $where, $limit]) {
            do {
                if (($this->clock)() >= $deadline) {
                    return false;
                }
                $removed = $this->resource->getConnection()->query(
                    'DELETE FROM ' . $this->table($table) . ' WHERE ' . $where . ' LIMIT ' . $limit,
                    [$formId]
                )->rowCount();
            } while ($removed >= $limit);
        }
        try {
            $this->formRepository->delete($this->formRepository->getById($formId));
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder could not delete purged form ' . $formId . ': ' . $e->getMessage());
        }
        return true;
    }

    /**
     * Hinted keyset ids, then a primary-key delete: "DELETE … WHERE form_id = ? ORDER BY … LIMIT" let MySQL pick a
     * (form_id, x) index and filesort the whole form for every batch.
     */
    private function purgeSubmissions(int $formId, float $deadline): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->table('furan_formbuilder_submission');
        $after = 0;
        do {
            if (($this->clock)() >= $deadline) {
                return false;
            }
            $ids = array_map('intval', $connection->fetchCol(
                'SELECT submission_id FROM ' . $table . $this->keysetIndex->forForm() . ' WHERE form_id = ? AND submission_id > ?'
                . ' ORDER BY submission_id LIMIT ' . self::BATCH_SIZE,
                [$formId, $after]
            ));
            if ($ids === []) {
                return true;
            }
            $this->deliveryCleaner->deleteFor($ids);
            // Ids are ints, so inlining them is injection-safe.
            $connection->query('DELETE FROM ' . $table . ' WHERE submission_id IN (' . implode(',', $ids) . ')');
            $after = (int)end($ids);
        } while (count($ids) >= self::BATCH_SIZE);
        return true;
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
