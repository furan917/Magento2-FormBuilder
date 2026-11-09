<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Claim;

use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/** Guest submission claims: lookup by keyed email fingerprint on the (email_hash, customer_id) index. */
class ClaimService
{
    private const BATCH = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EmailHasher $emailHasher,
        private readonly Share $share,
        private readonly StoreManagerInterface $storeManager,
        private readonly Respondents $respondents
    ) {
    }

    /** True when at least one unlinked guest submission exists for the email in the customer's account scope. */
    public function hasUnlinked(string $email, int $websiteId): bool
    {
        $candidates = $this->emailHasher->candidates($email);
        if ($candidates === []) {
            return false;
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('furan_formbuilder_submission'), ['submission_id'])
            ->where('email_hash IN (?)', array_values($candidates))
            ->where('customer_id IS NULL');
        if ($this->share->isWebsiteScope()) {
            $select->where('store_id IN (?)', $this->websiteStoreIds($websiteId));
        }

        return $connection->fetchOne($select->limit(1)) !== false;
    }

    /**
     * Links the email's unlinked guest submissions to the customer; returns the linked count.
     * Sets customer_id only: group, store and created_at stay as analytics dimensions.
     */
    public function claim(int $customerId, string $email, int $websiteId): int
    {
        $storeIds = $this->share->isWebsiteScope()
            ? $this->websiteStoreIds($websiteId)
            : null;
        $linked = 0;
        foreach ($this->emailHasher->candidates($email) as $hash) {
            $lastId = 0;
            do {
                $ids = $this->nextBatch($hash, $storeIds, $lastId);
                if ($ids === []) {
                    break;
                }
                $lastId = (int)end($ids);
                $linked += $this->link($customerId, $ids);
            } while (count($ids) === self::BATCH);
        }

        return $linked;
    }

    /**
     * @param int[]|null $storeIds
     * @return int[]
     */
    private function nextBatch(string $hash, ?array $storeIds, int $afterId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('furan_formbuilder_submission'), ['submission_id'])
            ->where('email_hash = ?', $hash)
            ->where('customer_id IS NULL')
            ->where('submission_id > ?', $afterId)
            ->order('submission_id ASC')
            ->limit(self::BATCH);
        if ($storeIds !== null) {
            $select->where('store_id IN (?)', $storeIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /** @param int[] $ids */
    private function link(int $customerId, array $ids): int
    {
        $connection = $this->resource->getConnection();
        // The IS NULL guard makes a concurrent or replayed claim harmless. Owned rows are replied to in the
        // account, so guest reply links issued before the claim stop working.
        $connection->beginTransaction();
        try {
            $count = (int)$connection->update(
                $this->resource->getTableName('furan_formbuilder_submission'),
                ['customer_id' => $customerId, 'thread_token_version' => new \Zend_Db_Expr('thread_token_version + 1')],
                ['submission_id IN (?)' => $ids, 'customer_id IS NULL']
            );
            if ($count > 0) {
                $this->rekeyRespondents($customerId, $ids);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $count;
    }

    /**
     * Guest 'e' respondent keys of rows now owned by the customer become the customer's 'c' key,
     * so the survey stays answered and edit-previous finds the row.
     *
     * @param int[] $ids
     */
    private function rekeyRespondents(int $customerId, array $ids): void
    {
        // Ids and customer id are ints, so inlining them is injection-safe.
        $rows = $this->resource->getConnection()->fetchAll(
            'SELECT r.form_id, r.respondent_key FROM ' . $this->resource->getTableName('furan_formbuilder_respondent')
            . ' r JOIN ' . $this->resource->getTableName('furan_formbuilder_submission')
            . ' s ON s.submission_id = r.submission_id WHERE r.submission_id IN (' . implode(',', $ids) . ')'
            . ' AND s.customer_id = ' . $customerId . " AND r.respondent_key LIKE 'e%'"
        );
        foreach ($rows as $row) {
            $this->respondents->rekey(
                (int)$row['form_id'],
                (string)$row['respondent_key'],
                $this->respondents->customerKey($customerId)
            );
        }
    }

    /** @return int[] */
    private function websiteStoreIds(int $websiteId): array
    {
        /** @var Website $website */
        $website = $this->storeManager->getWebsite($websiteId);

        return array_values($website->getStoreIds());
    }
}
