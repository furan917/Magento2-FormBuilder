<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Moves email fingerprints (and guest respondent keys) fingerprinted with an older crypt key to the newest key.
 */
class Rehasher
{
    private const PROBE_EMAIL = 'probe@example.invalid';

    /** @var array<int, array<string, mixed>> settings by form id */
    private array $formSettings = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EmailHasher $emailHasher,
        private readonly EmailResolver $emailResolver,
        private readonly Respondents $respondents,
        private readonly Json $json,
        private readonly KeysetIndex $keysetIndex
    ) {
    }

    /**
     * @return array{scanned: int, rekeyed: int, unresolved: int, respondent_keys: int}
     */
    public function run(int $batchSize, ?int $formId): array
    {
        $stats = ['scanned' => 0, 'rekeyed' => 0, 'unresolved' => 0, 'respondent_keys' => 0];
        $versions = $this->emailHasher->candidates(self::PROBE_EMAIL);
        if (count($versions) < 2) {
            return $stats;
        }
        $newest = (int)array_key_last($versions);
        $last = 0;
        do {
            $rows = $this->fetchBatch($last, $batchSize, $formId);
            if ($rows === []) {
                break;
            }
            $last = (int)end($rows)['submission_id'];
            $stats['scanned'] += count($rows);
            $this->processBatch(array_filter(
                $rows,
                static fn(array $row): bool => (int)$row['email_hash_version'] !== $newest
            ), $stats);
        } while (count($rows) === $batchSize);

        return $stats;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{scanned: int, rekeyed: int, unresolved: int, respondent_keys: int} $stats
     */
    private function processBatch(array $rows, array &$stats): void
    {
        if ($rows === []) {
            return;
        }
        $emails = $this->emailResolver->resolve($rows, $this->settingsFor($rows));
        // One commit per batch rather than a log flush per row.
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $this->rekeyRows($rows, $emails, $stats);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $emails
     * @param array{scanned: int, rekeyed: int, unresolved: int, respondent_keys: int} $stats
     */
    private function rekeyRows(array $rows, array $emails, array &$stats): void
    {
        foreach ($rows as $row) {
            $id = (int)$row['submission_id'];
            $email = $emails[$id] ?? null;
            $old = (string)$row['email_hash'];
            $candidates = $email === null ? [] : $this->emailHasher->candidates($email);
            $new = $email === null ? null : $this->emailHasher->hash($email);
            if ($new === null || ($candidates[(int)$row['email_hash_version']] ?? null) !== $old) {
                $stats['unresolved']++;
                continue;
            }
            if (!$this->guardedUpdate($id, $old, $new)) {
                continue;
            }
            $stats['rekeyed']++;
            if ($row['customer_id'] === null) {
                $this->respondents->rekey((int)$row['form_id'], 'e' . $old, 'e' . $new['hash']);
                $stats['respondent_keys']++;
            }
        }
    }

    /**
     * @param array{hash: string, version: int} $new
     */
    private function guardedUpdate(int $submissionId, string $old, array $new): bool
    {
        return $this->connection()->update(
            $this->resource->getTableName('furan_formbuilder_submission'),
            ['email_hash' => $new['hash'], 'email_hash_version' => $new['version']],
            ['submission_id = ?' => $submissionId, 'email_hash = ?' => $old]
        ) > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function settingsFor(array $rows): array
    {
        $ids = array_values(array_diff(array_unique(array_map(
            static fn(array $row): int => (int)$row['form_id'],
            $rows
        )), array_keys($this->formSettings)));
        if ($ids !== []) {
            $sql = 'SELECT form_id, settings_json FROM ' . $this->resource->getTableName('furan_formbuilder_form')
                . ' WHERE form_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($this->connection()->fetchAll($sql, $ids) as $form) {
                $this->formSettings[(int)$form['form_id']] = $this->decode((string)$form['settings_json']);
            }
        }
        return $this->formSettings;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        try {
            $data = $this->json->unserialize($json);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBatch(int $last, int $limit, ?int $formId): array
    {
        $sql = 'SELECT submission_id, form_id, customer_id, payload_json, email_hash, email_hash_version FROM '
            . $this->resource->getTableName('furan_formbuilder_submission')
            // Without a hint MySQL skip-scans the email_hash index and sorts every remaining row per batch.
            . ($formId !== null ? $this->keysetIndex->forForm() : $this->keysetIndex->forPrimary())
            . ' WHERE submission_id > :last AND email_hash IS NOT NULL';
        $bind = [':last' => $last];
        if ($formId !== null) {
            $sql .= ' AND form_id = :form';
            $bind[':form'] = $formId;
        }
        return $this->connection()->fetchAll($sql . ' ORDER BY submission_id LIMIT ' . $limit, $bind);
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
