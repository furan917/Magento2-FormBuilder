<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Export;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\ResourceModel\Submission\CollectionFactory;
use Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\File\WriteInterface;

class CsvExporter
{
    private const PAGE_SIZE = 500;
    private const PRODUCT_HEADERS = [
        'Product ID', 'Product SKU', 'Product name', 'Product URL', 'Product price', 'Product currency',
    ];
    private const PRODUCT_KEYS = ['id', 'sku', 'name', 'url', 'price', 'currency'];
    private const BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Filesystem $filesystem,
        private readonly ResourceConnection $resource,
        private readonly KeysetIndex $keysetIndex,
        private readonly ValueFormatter $formatter,
        private readonly SubmissionStatus $statuses
    ) {
    }

    /** @return string path relative to var/ of the generated CSV */
    public function export(FormInterface $form): string
    {
        $columns = $this->schemaColumns($form->getSchema());
        // Older payloads may carry fields no longer in the schema; the header must be known before writing rows.
        $hasContext = false;
        foreach ($this->submissions($form, ['payload_json', 'context_json']) as $submission) {
            $hasContext = $hasContext || $submission->getContext() !== null;
            foreach ($submission->getPayload() as $entry) {
                $name = (string)($entry['name'] ?? '');
                if ($name !== '' && !isset($columns[$name])) {
                    $columns[$name] = [
                        'name' => $name,
                        'label' => (string)($entry['label'] ?? $name),
                        'type' => (string)($entry['type'] ?? ''),
                    ];
                }
            }
        }

        $path = 'export/furan_formbuilder/' . bin2hex(random_bytes(16)) . '.csv';
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $file = $directory->openFile($path, 'w');
        try {
            $file->write(self::BOM);
            $header = ['Submission ID', 'Created At', 'Store ID', 'Customer ID', 'Reference', 'Status'];
            foreach ($columns as $column) {
                array_push($header, ...$this->formatter->csvHeaders($column));
            }
            if ($hasContext) {
                array_push($header, ...self::PRODUCT_HEADERS);
            }
            $this->write($file, $header);
            $labels = array_map($this->formatter->optionLabels(...), $columns);
            foreach ($this->submissions($form) as $submission) {
                $values = [];
                foreach ($submission->getPayload() as $entry) {
                    $values[(string)($entry['name'] ?? '')] = $entry['value'] ?? '';
                }
                $row = [
                    $submission->getId(),
                    $submission->getCreatedAt(),
                    $submission->getStoreId(),
                    $submission->getCustomerId(),
                    $submission->getReference(),
                    (string)$this->statuses->getLabel($submission->getStatus()),
                ];
                $plain = [];
                foreach ($columns as $name => $column) {
                    $plainColumns = $this->formatter->csvPlainColumns($column);
                    $value = ValueFormatter::withLabels($labels[$name], $values[$name] ?? '');
                    foreach ($this->formatter->csvCells($column, $value) as $i => $cell) {
                        $plain[count($row)] = in_array($i, $plainColumns, true)
                            && preg_match(ValueFormatter::COORDINATE, (string)$cell) === 1;
                        $row[] = $cell;
                    }
                }
                if ($hasContext) {
                    $context = $submission->getContext() ?? [];
                    foreach (self::PRODUCT_KEYS as $key) {
                        $row[] = ValueFormatter::flatten($context[$key] ?? '');
                    }
                }
                $this->write($file, $row, array_keys(array_filter($plain)));
            }
            $file->close();
        } catch (\Throwable $e) {
            $file->close();
            $directory->delete($path);
            throw $e;
        }
        return $path;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>> field items keyed by name
     */
    private function schemaColumns(array $schema): array
    {
        $columns = [];
        foreach (SchemaFields::byName($schema) as $name => $item) {
            $columns[$name] = [
                'label' => (string)($item['label'] ?? $name),
                'type' => (string)($item['type'] ?? ''),
            ] + $item;
        }
        return $columns;
    }

    /**
     * @param string[]|null $onlyFields
     * @return \Generator<int, SubmissionInterface>
     */
    private function submissions(FormInterface $form, ?array $onlyFields = null): \Generator
    {
        // Keyset on the hinted (form_id, submission_id) index, then a primary-key load of that page. No setPageSize():
        // it makes every page COUNT(*) the rest of the form.
        $sql = 'SELECT submission_id FROM ' . $this->resource->getTableName('furan_formbuilder_submission')
            . $this->keysetIndex->forForm()
            . ' WHERE form_id = ? AND submission_id > ? ORDER BY submission_id LIMIT ' . self::PAGE_SIZE;
        $after = 0;
        do {
            $ids = array_map('intval', $this->resource->getConnection()->fetchCol($sql, [(int)$form->getId(), $after]));
            if ($ids === []) {
                return;
            }
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('submission_id', ['in' => $ids]);
            if ($onlyFields !== null) {
                $collection->addFieldToSelect(array_merge(['submission_id'], $onlyFields));
            }
            $collection->setOrder('submission_id', 'ASC');
            foreach ($collection->getItems() as $item) {
                /** @var SubmissionInterface $item */
                yield $item;
            }
            $after = (int)end($ids);
        } while (true);
    }

    /**
     * @param array<int, mixed> $row
     * @param int[] $plainIndexes cells that are bare numbers and skip the formula guard
     */
    private function write(WriteInterface $file, array $row, array $plainIndexes = []): void
    {
        foreach ($row as $i => $cell) {
            $cell = (string)$cell;
            $guard = $cell !== '' && strpbrk($cell[0], "=+-@\t\r\n") !== false && !in_array($i, $plainIndexes, true);
            $row[$i] = $guard ? "'" . $cell : $cell;
        }
        $file->writeCsv($row);
    }
}
