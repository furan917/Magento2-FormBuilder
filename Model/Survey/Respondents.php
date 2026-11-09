<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Survey;

use Furan\FormBuilder\Model\Submission\EmailHasher;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

class Respondents
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EmailHasher $emailHasher
    ) {
    }

    public function customerKey(int $customerId): string
    {
        return 'c' . hash('sha256', (string)$customerId, true);
    }

    /** Newest-key key, for inserts. */
    public function emailKey(string $email): ?string
    {
        $hash = $this->emailHasher->hash($email);
        return $hash === null ? null : 'e' . $hash['hash'];
    }

    /**
     * One key per key version, for lookups.
     *
     * @return string[]
     */
    public function emailKeys(string $email): array
    {
        return array_values(array_map(
            static fn(string $hash): string => 'e' . $hash,
            $this->emailHasher->candidates($email)
        ));
    }

    /**
     * @param string[] $keys
     * @return array{submission_id: ?int}|null null when no row; submission_id null when detached
     */
    public function find(int $formId, array $keys): ?array
    {
        if ($keys === []) {
            return null;
        }
        $row = $this->resource->getConnection()->fetchRow(
            'SELECT submission_id FROM ' . $this->resource->getTableName('furan_formbuilder_respondent')
            . ' WHERE form_id = ? AND respondent_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') LIMIT 1',
            array_merge([$formId], array_values($keys))
        );
        if (!is_array($row)) {
            return null;
        }
        return ['submission_id' => $row['submission_id'] === null ? null : (int)$row['submission_id']];
    }

    /** True when the key has answered any one-response form; one seek on the respondent_key index. */
    public function hasAnswered(string $key): bool
    {
        return $this->resource->getConnection()->fetchOne(
            'SELECT 1 FROM ' . $this->resource->getTableName('furan_formbuilder_respondent')
            . ' WHERE respondent_key = ? LIMIT 1',
            [$key]
        ) !== false;
    }

    /**
     * Raw adapter insert: a resource-model save would wrap the duplicate key in AlreadyExistsException.
     *
     * @throws AlreadyAnsweredException
     */
    public function add(int $formId, string $key, int $submissionId): void
    {
        try {
            $this->resource->getConnection()->insert(
                $this->resource->getTableName('furan_formbuilder_respondent'),
                ['form_id' => $formId, 'respondent_key' => $key, 'submission_id' => $submissionId]
            );
        } catch (DuplicateException $e) {
            throw new AlreadyAnsweredException('Already answered.', 0, $e);
        }
    }

    /** Records the key unless the form already has it; never throws for a duplicate. */
    public function addIfAbsent(int $formId, string $key, int $submissionId): void
    {
        $this->resource->getConnection()->query(
            'INSERT IGNORE INTO ' . $this->resource->getTableName('furan_formbuilder_respondent')
            . ' (form_id, respondent_key, submission_id) VALUES (?, ?, ?)',
            [$formId, $key, $submissionId]
        );
    }

    /**
     * Catches rows written under an older crypt key version. With one version the insert hits the same
     * primary key, so no lookup is needed.
     *
     * @throws AlreadyAnsweredException
     */
    public function assertNotAnswered(int $formId, string $email): void
    {
        $keys = $this->emailKeys($email);
        if (count($keys) > 1 && $this->find($formId, $keys) !== null) {
            throw new AlreadyAnsweredException('Already answered.');
        }
    }

    /**
     * Keeps the row but frees it from the submission, so a customer's own delete does not reopen the survey.
     *
     * @param int[] $submissionIds
     */
    public function detach(array $submissionIds): int
    {
        if ($submissionIds === []) {
            return 0;
        }
        return (int)$this->resource->getConnection()->update(
            $this->resource->getTableName('furan_formbuilder_respondent'),
            ['submission_id' => null],
            ['submission_id IN (?)' => array_values($submissionIds)]
        );
    }

    /**
     * For erase: removes detached rows too, which the submission cascade cannot reach.
     *
     * @param string[] $keys
     */
    public function removeByKeys(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }
        return (int)$this->resource->getConnection()->delete(
            $this->resource->getTableName('furan_formbuilder_respondent'),
            ['respondent_key IN (?)' => array_values($keys)]
        );
    }

    /**
     * Moves a respondent row to a new key; drops the old row when the new key is already taken for the form.
     */
    public function rekey(int $formId, string $oldKey, string $newKey): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('furan_formbuilder_respondent');
        $where = ['form_id = ?' => $formId, 'respondent_key = ?' => $oldKey];
        try {
            $connection->update($table, ['respondent_key' => $newKey], $where);
        } catch (DuplicateException) {
            $connection->delete($table, $where);
        }
    }
}
