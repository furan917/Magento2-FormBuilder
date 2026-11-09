<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Privacy;

use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Survey\Respondents;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\App\ResourceConnection;

/**
 * GDPR erase. Submissions go through BatchDeleter (journaled); messages and attached respondents cascade by FK,
 * files are unlinked by FK and removed by FileCleanup.
 */
class Eraser
{
    public function __construct(private readonly ResourceConnection $resource, private readonly BatchDeleter $batchDeleter, private readonly Respondents $respondents, private readonly EmailHasher $emailHasher, private readonly AccountLookup $accounts, private readonly SubmissionDeliveryCleaner $deliveryCleaner)
    {
    }

    /**
     * @param int[] $keepFormIds submissions of these forms are left alone
     * @param bool $keepRespondents detach instead of removing survey respondent rows (customer's own delete)
     * @return int deleted submissions
     */
    public function eraseCustomer(int $customerId, array $keepFormIds = [], bool $keepRespondents = false): int
    {
        $sql = 'SELECT submission_id FROM ' . $this->table('furan_formbuilder_submission')
            . ' WHERE customer_id = ? AND submission_id > ?'
            . ($keepFormIds === [] ? '' : ' AND form_id NOT IN (' . implode(',', array_map('intval', $keepFormIds)) . ')')
            . ' ORDER BY submission_id LIMIT ' . BatchDeleter::BATCH_SIZE;
        $deleted = $this->eraseWhere($sql, [$customerId], [], $keepRespondents);

        $this->resource->getConnection()->delete(
            $this->table('furan_formbuilder_draft'),
            ['customer_id = ?' => $customerId]
        );
        if (!$keepRespondents) {
            $this->respondents->removeByKeys([$this->respondents->customerKey($customerId)]);
        }
        return $deleted;
    }

    /**
     * Matches every fingerprint key version, guest and account-linked rows alike, and runs the full customer
     * erase (drafts, survey keys) for every account registered with the address.
     */
    public function eraseEmail(string $email): int
    {
        $deleted = 0;
        foreach ($this->accounts->customerIds($email) as $customerId) {
            $deleted += $this->eraseCustomer($customerId);
        }
        $sql = 'SELECT submission_id FROM ' . $this->table('furan_formbuilder_submission')
            . ' WHERE email_hash = ? AND submission_id > ? ORDER BY submission_id LIMIT ' . BatchDeleter::BATCH_SIZE;
        foreach ($this->emailHasher->candidates($email) as $hash) {
            $deleted += $this->eraseWhere($sql, [$hash], [], false);
        }
        $this->respondents->removeByKeys($this->respondents->emailKeys($email));
        return $deleted;
    }

    /**
     * What an erase would remove, for the confirmation step: one COUNT per form, never the rows themselves.
     *
     * @return array{total: int, accounts: int, forms: array<int, array{form_id: int, title: string, count: int}>}
     */
    public function preview(int $customerId, string $email): array
    {
        $customerIds = $customerId > 0 ? [$customerId] : $this->accounts->customerIds($email);
        $hashes = $customerId > 0 ? [] : array_values($this->emailHasher->candidates($email));
        $submissions = $this->table('furan_formbuilder_submission');
        $seeks = [];
        if ($customerIds !== []) {
            $seeks[] = 'SELECT submission_id, form_id FROM ' . $submissions
                . ' WHERE customer_id IN (' . implode(',', array_map('intval', $customerIds)) . ')';
        }
        if ($hashes !== []) {
            // Raw binds do not expand arrays, so each fingerprint gets its own placeholder.
            $seeks[] = 'SELECT submission_id, form_id FROM ' . $submissions
                . ' WHERE email_hash IN (' . implode(', ', array_fill(0, count($hashes), '?')) . ')';
        }
        $result = ['total' => 0, 'accounts' => count($customerIds), 'forms' => []];
        if ($seeks === []) {
            return $result;
        }
        // One index seek per branch; UNION drops rows matched by both an account and a fingerprint.
        $rows = $this->resource->getConnection()->fetchAll(
            'SELECT u.form_id, f.title, COUNT(*) AS n FROM (' . implode(' UNION ', $seeks) . ') u'
            . ' LEFT JOIN ' . $this->table('furan_formbuilder_form') . ' f ON f.form_id = u.form_id'
            . ' GROUP BY u.form_id, f.title ORDER BY n DESC',
            $hashes
        );
        foreach ($rows as $row) {
            $result['forms'][] = [
                'form_id' => (int)$row['form_id'],
                'title' => (string)($row['title'] ?? ''),
                'count' => (int)$row['n'],
            ];
            $result['total'] += (int)$row['n'];
        }
        return $result;
    }

    /**
     * Keyset loop: each batch is its own transaction and the cursor stays valid as rows disappear.
     *
     * @param array<int, int|string> $leading binds before the cursor
     * @param array<int, int|string> $trailing binds after the cursor
     */
    private function eraseWhere(string $sql, array $leading, array $trailing, bool $detachRespondents): int
    {
        $connection = $this->resource->getConnection();
        $after = 0;
        $deleted = 0;
        do {
            $ids = array_map('intval', $connection->fetchCol($sql, array_merge($leading, [$after], $trailing)));
            if ($ids === []) {
                break;
            }
            $deleted += $detachRespondents ? $this->deleteDetaching($ids) : $this->batchDeleter->deleteBatch($ids);
            $after = max($ids);
        } while (count($ids) >= BatchDeleter::BATCH_SIZE);

        return $deleted;
    }

    /** @param int[] $ids */
    private function deleteDetaching(array $ids): int
    {
        // Autocommitted batches, so they must run before the transaction opens.
        $this->deliveryCleaner->deleteFor($ids);
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->respondents->detach($ids);
            $count = $this->batchDeleter->deleteBatch($ids);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return $count;
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
