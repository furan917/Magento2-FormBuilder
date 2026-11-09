<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Privacy;

use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Submission\Reference;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

/**
 * GDPR export: one zip with data.json, data.csv and the uploaded files. Rows are read in keyset batches and
 * written to temp files, so memory stays constant. The zip is built under var/ (always node-local); uploads are
 * copied there through FileStorage, which works with remote storage, one archive batch at a time.
 */
class Exporter
{
    private const DIRECTORY = 'export/furan_formbuilder';
    private const FILES_PER_ARCHIVE_OPEN = 200;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;
    private const CSV_HEADER = [
        'Submission ID', 'Reference', 'Form', 'Store ID', 'Created At', 'Status', 'Answers', 'Context', 'Messages',
    ];

    public function __construct(private readonly ResourceConnection $resource, private readonly EmailHasher $emailHasher, private readonly Filesystem $filesystem, private readonly FileStorage $fileStorage, private readonly ValueFormatter $formatter, private readonly AccountLookup $accounts, private readonly Pool $fieldTypes, private readonly SubmissionStatus $statuses)
    {
    }

    /** @return string zip path relative to var/ */
    public function exportCustomer(int $customerId): string
    {
        return $this->build([['customer_id', $customerId]]);
    }

    /**
     * Rows of every account registered with the address, then rows matching any of its fingerprints.
     *
     * @return string zip path relative to var/
     */
    public function exportEmail(string $email): string
    {
        return $this->build(array_merge(
            array_map(static fn (int $id): array => ['customer_id', $id], $this->accounts->customerIds($email)),
            array_map(
                static fn (string $hash): array => ['email_hash', $hash],
                array_values($this->emailHasher->candidates($email))
            )
        ));
    }

    /**
     * @param array<int, array{string, int|string}> $seeks column (allowlisted by callers) and value
     * @throws LocalizedException
     */
    private function build(array $seeks): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new LocalizedException(__('The PHP zip extension is required for privacy exports.'));
        }
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $var->create(self::DIRECTORY);
        $token = bin2hex(random_bytes(16));
        $relative = self::DIRECTORY . '/' . $token . '.zip';
        $zipPath = $var->getAbsolutePath($relative);
        $temp = [];
        foreach (['json', 'csv', 'manifest', 'copy'] as $kind) {
            $temp[$kind] = $var->getAbsolutePath(self::DIRECTORY . '/' . $token . '.' . $kind . '.tmp');
        }
        try {
            $this->writeData($seeks, $temp);
            $copies = [];
            try {
                $this->writeArchive($zipPath, $temp, $copies);
            } finally {
                array_map($this->discard(...), $copies);
            }
        } catch (\Throwable $e) {
            $this->discard($zipPath);
            throw $e;
        } finally {
            foreach ($temp as $path) {
                $this->discard($path);
            }
        }
        return $relative;
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            unlink($path); // phpcs:ignore Magento2.Functions.DiscouragedFunction -- deletes a local temp copy of a media file
        }
    }

    /**
     * @param array<int, array{string, int|string}> $seeks
     * @param array<string, string> $temp
     */
    private function writeData(array $seeks, array $temp): void
    {
        $json = $this->open($temp['json']);
        $csv = $this->open($temp['csv']);
        $manifest = $this->open($temp['manifest']);
        $missing = [];
        $seen = [];
        $recipients = [];
        try {
            fwrite($csv, "\xEF\xBB\xBF");
            fputcsv($csv, self::CSV_HEADER, ',', '"', '');
            fwrite($json, '{"submissions":[');
            $first = true;
            foreach ($this->batches($seeks) as $rows) {
                $forms = $this->formIdentifiers($rows);
                $messages = $this->messages($rows);
                $this->collectRecipients($rows, $recipients);
                foreach ($rows as $row) {
                    $id = (int)$row['submission_id'];
                    // An account's rows can also match the email fingerprint.
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $item = $this->item($row, $forms, $messages[$id] ?? [], $manifest, $missing);
                    fwrite($json, ($first ? '' : ',') . json_encode($item, self::JSON_FLAGS));
                    $first = false;
                    fputcsv($csv, $this->csvRow($item), ',', '"', '');
                }
            }
            fwrite($json, '],"webhook_recipients":' . json_encode(array_values($recipients), self::JSON_FLAGS)
                . ',"missing_files":' . json_encode($missing, self::JSON_FLAGS) . '}');
        } finally {
            fclose($json);
            fclose($csv);
            fclose($manifest);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $forms
     * @param array<int, array<string, mixed>> $messages
     * @param resource $manifest
     * @param array<int, array{submission_id: int, name: string}> $missing
     * @return array<string, mixed>
     */
    private function item(array $row, array $forms, array $messages, $manifest, array &$missing): array
    {
        $id = (int)$row['submission_id'];
        $payload = json_decode((string)$row['payload_json'], true);
        $used = [];
        $answers = [];
        foreach (is_array($payload) ? $payload : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['value'] ?? null;
            if ($this->fieldTypes->isFile((string)($entry['type'] ?? '')) && is_array($value) && is_string($value['file'] ?? null)) {
                $name = (string)($value['name'] ?? '');
                $stored = $this->storedUpload($value['file']);
                if ($stored === null) {
                    $missing[] = ['submission_id' => $id, 'name' => $name];
                } else {
                    fwrite($manifest, json_encode(
                        [$this->zipName($id, $name, $used), $stored],
                        self::JSON_FLAGS
                    ) . "\n");
                }
                $value = $name;
            }
            $answers[] = [
                'name' => (string)($entry['name'] ?? ''),
                'label' => (string)($entry['label'] ?? ''),
                'type' => (string)($entry['type'] ?? ''),
                'value' => $value,
            ];
        }
        $context = json_decode((string)($row['context_json'] ?? ''), true);

        return [
            'id' => $id,
            'reference' => Reference::toString((string)($row['reference'] ?? '')),
            'form' => $forms[(int)$row['form_id']] ?? '',
            'store_id' => $row['store_id'] === null ? null : (int)$row['store_id'],
            'created_at' => (string)$row['created_at'],
            'status' => SubmissionStatus::CODES[(int)$row['status']] ?? (string)$row['status'],
            'status_label' => (string)$this->statuses->getLabel((int)$row['status']),
            'answers' => $answers,
            'context' => is_array($context) ? $context : null,
            'messages' => array_map(static fn (array $m): array => [
                'author' => (string)$m['author'],
                'body' => (string)$m['body'],
                'created_at' => (string)$m['created_at'],
            ], $messages),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, string>
     */
    private function csvRow(array $item): array
    {
        $answers = [];
        foreach ($item['answers'] as $answer) {
            $answers[] = $answer['label'] . ': ' . $this->formatter->toText($answer, $answer['value']);
        }
        $messages = array_map(
            static fn (array $m): string => $m['created_at'] . ' ' . $m['author'] . ': ' . $m['body'],
            $item['messages']
        );
        $row = [
            $item['id'], $item['reference'], $item['form'], $item['store_id'], $item['created_at'], $item['status_label'],
            implode("\n", $answers),
            $item['context'] === null ? '' : json_encode($item['context'], self::JSON_FLAGS),
            implode("\n", $messages),
        ];
        return array_map(static function ($cell): string {
            $cell = (string)$cell;
            return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
        }, $row);
    }

    /** @param array<string, true> $used names taken for this submission */
    private function zipName(int $submissionId, string $original, array &$used): string
    {
        $name = trim((string)preg_replace('/[^\w.\- ]/u', '_', basename(str_replace('\\', '/', $original))));
        if ($name === '' || trim($name, '.') === '') {
            $name = 'file';
        }
        $candidate = $name;
        for ($i = 1; isset($used[$candidate]); $i++) {
            $dot = strrpos($name, '.');
            $candidate = $dot === false || $dot === 0
                ? $name . '_' . $i
                : substr($name, 0, $dot) . '_' . $i . substr($name, $dot);
        }
        $used[$candidate] = true;

        return 'files/' . $submissionId . '/' . $candidate;
    }

    /** The stored path, only when it matches the upload pattern and the file still exists. */
    private function storedUpload(string $relative): ?string
    {
        if (preg_match(FileStorage::PATH_PATTERN, $relative) !== 1) {
            return null;
        }
        try {
            return $this->fileStorage->downloadPath($relative);
        } catch (LocalizedException) {
            return null;
        }
    }

    /**
     * addFile() reads its source only when the archive closes, so the local copies of each batch live until then.
     *
     * @param array<string, string> $temp
     * @param string[] $copies local copies not yet removed, for the caller's cleanup
     */
    private function writeArchive(string $zipPath, array $temp, array &$copies): void
    {
        $zip = new \ZipArchive();
        $this->openArchive($zip, $zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($temp['json'], 'data.json');
        $zip->addFile($temp['csv'], 'data.csv');
        $count = 2;
        $manifest = fopen($temp['manifest'], 'rb');
        if ($manifest === false) {
            throw new LocalizedException(__('The export file could not be read.'));
        }
        try {
            while (($line = fgets($manifest)) !== false) {
                [$name, $stored] = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
                $local = $temp['copy'] . '.' . $count;
                try {
                    $this->fileStorage->copyToLocal($stored, $local);
                } catch (LocalizedException | FileSystemException) {
                    // Removed since the data pass; data.json still names it.
                    $this->discard($local);
                    continue;
                }
                $copies[] = $local;
                $zip->addFile($local, $name);
                $count++;
                if ($count % self::FILES_PER_ARCHIVE_OPEN === 0) {
                    $this->closeArchive($zip);
                    array_map($this->discard(...), $copies);
                    $copies = [];
                    $this->openArchive($zip, $zipPath, \ZipArchive::CREATE);
                }
            }
        } finally {
            fclose($manifest);
        }
        $this->closeArchive($zip);
    }

    private function openArchive(\ZipArchive $zip, string $path, int $flags): void
    {
        if ($zip->open($path, $flags) !== true) {
            throw new LocalizedException(__('The export archive could not be created.'));
        }
    }

    private function closeArchive(\ZipArchive $zip): void
    {
        if (!$zip->close()) {
            throw new LocalizedException(__('The export archive could not be written.'));
        }
    }

    /** @return resource */
    private function open(string $path)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new LocalizedException(__('The export file could not be created.'));
        }
        return $handle;
    }

    /**
     * @param array<int, array{string, int|string}> $seeks
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    private function batches(array $seeks): \Generator
    {
        $connection = $this->resource->getConnection();
        foreach ($seeks as [$column, $value]) {
            $sql = 'SELECT submission_id, reference, form_id, store_id, created_at, status, payload_json, context_json FROM '
                . $this->resource->getTableName('furan_formbuilder_submission')
                . ' WHERE ' . $column . ' = ? AND submission_id > ? ORDER BY submission_id LIMIT '
                . BatchDeleter::BATCH_SIZE;
            $after = 0;
            do {
                $rows = $connection->fetchAll($sql, [$value, $after]);
                if ($rows === []) {
                    break;
                }
                yield $rows;
                $after = (int)end($rows)['submission_id'];
            } while (count($rows) >= BatchDeleter::BATCH_SIZE);
        }
    }

    /**
     * Endpoints that successfully received any of these submissions (GDPR Art. 15 recipients). The host is the
     * one stored at delivery, never the endpoint URL, whose path or query may carry a token.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array{name: string, host: string}> $recipients
     */
    private function collectRecipients(array $rows, array &$recipients): void
    {
        $ids = array_map(static fn (array $r): int => (int)$r['submission_id'], $rows);
        $found = $this->resource->getConnection()->fetchAll(
            'SELECT DISTINCT r.endpoint_id, r.host, w.name FROM '
            . $this->resource->getTableName('furan_formbuilder_webhook_recipient') . ' AS r FORCE INDEX (PRIMARY) LEFT JOIN '
            . $this->resource->getTableName('furan_formbuilder_webhook')
            . ' AS w ON w.endpoint_id = r.endpoint_id AND w.purge_requested_at IS NULL'
            . ' WHERE r.submission_id IN (' . implode(',', $ids) . ')'
        );
        foreach ($found as $row) {
            $recipients[$row['endpoint_id'] . '|' . $row['host']] = [
                'name' => (string)($row['name'] ?? __('Deleted endpoint')),
                'host' => (string)$row['host'],
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function formIdentifiers(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int)$r['form_id'], $rows)));
        $map = [];
        $found = $this->resource->getConnection()->fetchAll(
            'SELECT form_id, identifier FROM ' . $this->resource->getTableName('furan_formbuilder_form')
            . ' WHERE form_id IN (' . implode(',', array_map('intval', $ids)) . ')'
        );
        foreach ($found as $form) {
            $map[(int)$form['form_id']] = (string)$form['identifier'];
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function messages(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int)$r['submission_id'], $rows);
        $grouped = [];
        $found = $this->resource->getConnection()->fetchAll(
            'SELECT submission_id, author, body, created_at FROM '
            . $this->resource->getTableName('furan_formbuilder_message')
            . ' WHERE submission_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY submission_id, message_id'
        );
        foreach ($found as $message) {
            $grouped[(int)$message['submission_id']][] = $message;
        }
        return $grouped;
    }
}
