<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Export;

use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Export\CsvExporter;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Test\Unit\Helper\PointType;
use Furan\FormBuilder\Model\ResourceModel\Submission\Collection;
use Furan\FormBuilder\Model\ResourceModel\Submission\CollectionFactory;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface as DirectoryWrite;
use Magento\Framework\Filesystem\File\WriteInterface as FileWrite;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase
{
    private const REF = '0194f3a0-1111-7222-8333-444455556666';

    private string $keysetSql = '';
    /** @var int[] */
    private array $pagesRequested = [];

    /** @var string[] raw writes, in order */
    private array $written = [];

    /** @var array[] rows passed to writeCsv */
    private array $rows = [];

    private ?string $openedPath = null;

    private ?string $openedMode = null;

    private bool $closed = false;

    private bool $failWrites = false;

    /** @var string[] paths deleted from var/ */
    private array $deleted = [];

    /** @var string[] field lists passed to addFieldToSelect */
    private array $selected = [];

    private function submission(int $id, array $payload, ?array $context = null): SubmissionInterface
    {
        $s = $this->createStub(SubmissionInterface::class);
        $s->method('getId')->willReturn($id);
        $s->method('getCreatedAt')->willReturn('2026-01-02 03:04:05');
        $s->method('getStoreId')->willReturn(1);
        $s->method('getCustomerId')->willReturn(null);
        $s->method('getReference')->willReturn(self::REF);
        $s->method('getStatus')->willReturn(SubmissionInterface::STATUS_ANSWERED);
        $s->method('getPayload')->willReturn($payload);
        $s->method('getContext')->willReturn($context);
        return $s;
    }

    /** @param SubmissionInterface[][] $pages */
    private function exporter(array $pages, ?Pool $pool = null): CsvExporter
    {
        $this->pagesRequested = [];
        $this->written = [];
        $this->rows = [];
        $this->selected = [];
        $this->openedPath = null;
        $this->closed = false;
        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind) use ($pages): array {
            $this->keysetSql = $sql;
            $this->pagesRequested[] = $bind[1];
            foreach ($pages as $page) {
                if ($page && $page[0]->getId() > $bind[1]) {
                    return array_map(static fn ($s) => (string)$s->getId(), $page);
                }
            }
            return [];
        });
        $resource = $this->createStub(\Magento\Framework\App\ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $keyset = $this->createStub(\Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex::class);
        $keyset->method('forForm')->willReturn(' FORCE INDEX (`K`)');
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($pages) {
            $in = [];
            $collection = $this->createMock(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $condition) use (&$in, $collection) {
                    if ($field === 'submission_id') {
                        $in = array_map('intval', $condition['in']);
                    }
                    return $collection;
                }
            );
            $collection->method('addFieldToSelect')->willReturnCallback(
                function ($f) use ($collection) {
                    $this->selected[] = $f;
                    return $collection;
                }
            );
            $collection->method('setOrder')->willReturnSelf();
            // setPageSize() would make every page run COUNT(*) over the rest of the form (getCurPage -> getSize).
            $collection->expects($this->never())->method('setPageSize');
            $collection->method('getItems')->willReturnCallback(
                function () use (&$in, $pages) {
                    $items = [];
                    foreach ($pages as $page) {
                        foreach ($page as $submission) {
                            if (in_array((int)$submission->getId(), $in, true)) {
                                $items[] = $submission;
                            }
                        }
                    }
                    return $items;
                }
            );
            return $collection;
        });
        return new CsvExporter(
            $factory,
            $this->filesystem(),
            $resource,
            $keyset,
            new ValueFormatter($pool ?? FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()),
            new \Furan\FormBuilder\Model\Source\SubmissionStatus()
        );
    }

    private function filesystem(): Filesystem
    {
        $file = $this->createStub(FileWrite::class);
        $file->method('write')->willReturnCallback(function (string $d) {
            $this->written[] = $d;
            return strlen($d);
        });
        $file->method('writeCsv')->willReturnCallback(function (array $row) {
            if ($this->failWrites) {
                throw new \RuntimeException('disk full');
            }
            $this->rows[] = $row;
            return 1;
        });
        $file->method('close')->willReturnCallback(function () {
            $this->closed = true;
            return true;
        });
        $dir = $this->createStub(DirectoryWrite::class);
        $dir->method('openFile')->willReturnCallback(function (string $path, string $mode) use ($file) {
            $this->openedPath = $path;
            $this->openedMode = $mode;
            return $file;
        });
        $dir->method('delete')->willReturnCallback(function (string $path) {
            $this->deleted[] = $path;
            return true;
        });
        $fs = $this->createMock(Filesystem::class);
        $fs->method('getDirectoryWrite')->with(DirectoryList::VAR_DIR)->willReturn($dir);
        return $fs;
    }

    private function form(array $fields): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $items = array_map(
            static fn(array $f) => ['kind' => 'field'] + $f,
            $fields
        );
        $form->method('getSchema')->willReturn(['version' => 1, 'pages' => [['items' => $items]]]);
        return $form;
    }

    private function exportRows(CsvExporter $exporter, FormInterface $form): array
    {
        $exporter->export($form);
        return $this->rows;
    }

    public function testItUsesTheFieldTypesCsvHeadersAndCellsWhenTheTypeImplementsExportableTypeInterface(): void
    {
        $form = $this->form([['name' => 'p', 'label' => 'Spot', 'type' => 'point']]);
        $exporter = $this->exporter([[$this->submission(1, [
            ['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '3', 'y' => '4']],
        ])]]);
        $rows = $this->exportRows($exporter, $form);
        $this->assertSame(['Submission ID', 'Created At', 'Store ID', 'Customer ID', 'Reference', 'Status', 'Spot (x)', 'Spot (y)'], $rows[0]);
        $this->assertSame(['3', '4'], array_slice($rows[1], 6));
    }

    public function testItFallsBackToOneColumnWithThePlainValueForTypesWithoutExportableTypeInterface(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'A', 'type' => 'text'], ['name' => 'g', 'label' => 'G', 'type' => 'gone']]);
        $exporter = $this->exporter([[$this->submission(1, [
            ['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => ['one', 'two']],
            ['name' => 'g', 'label' => 'G', 'type' => 'gone', 'value' => 'v'],
        ])]]);
        $rows = $this->exportRows($exporter, $form);
        $this->assertSame(['A', 'G'], array_slice($rows[0], 6));
        $this->assertSame(['one, two', 'v'], array_slice($rows[1], 6));
    }

    public function testItExportsOptionLabelsAndKeepsValuesWhoseOptionIsGone(): void
    {
        $form = $this->form([
            ['name' => 'topic', 'label' => 'Topic', 'type' => 'select', 'options' => [['value' => 'sales', 'label' => 'Sales team']]],
            ['name' => 'tags', 'label' => 'Tags', 'type' => 'checkboxes', 'options' => [
                ['value' => 'opt_1', 'label' => 'Red'], ['value' => 'opt_3', 'label' => 'Blue'],
            ]],
        ]);
        $exporter = $this->exporter([[$this->submission(1, [
            ['name' => 'topic', 'label' => 'Topic', 'type' => 'select', 'value' => 'sales'],
            ['name' => 'tags', 'label' => 'Tags', 'type' => 'checkboxes', 'value' => ['opt_3', 'opt_9']],
            ['name' => 'old', 'label' => 'Old', 'type' => 'radio', 'value' => 'opt_2'],
        ])]]);

        $rows = $this->exportRows($exporter, $form);
        $this->assertSame(['Sales team', 'Blue, opt_9', 'opt_2'], array_slice($rows[1], 6));
    }

    public function testItEscapesCsvFormulaPrefixesInCellsReturnedByCustomTypes(): void
    {
        $form = $this->form([['name' => 'p', 'label' => 'Spot', 'type' => 'point']]);
        $exporter = $this->exporter([[$this->submission(1, [
            ['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '=1+1', 'y' => '@cmd']],
        ])]], FieldPools::structured(['point' => new PointType([])]));
        $rows = $this->exportRows($exporter, $form);
        $this->assertSame(["'=1+1", "'@cmd"], array_slice($rows[1], 6));
    }

    public function testItLeavesOnlyCoordinateShapedCellsInDeclaredPlainColumnsUnguarded(): void
    {
        $form = $this->form([['name' => 'p', 'label' => 'Spot', 'type' => 'point']]);
        $submissions = [
            $this->submission(1, [['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '-1.500000', 'y' => '-1.500000']]]),
            $this->submission(2, [['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '-=cmd', 'y' => '-5']]]),
        ];
        $exporter = $this->exporter([[$submissions[0]], [$submissions[1]]], FieldPools::structured(['point' => new PointType([0])]));
        $rows = $this->exportRows($exporter, $form);
        $this->assertSame(['-1.500000', "'-1.500000"], array_slice($rows[1], 6));
        $this->assertSame(["'-=cmd", "'-5"], array_slice($rows[2], 6));
    }

    public function testItWritesTheExportToATemporaryFileAndReturnsItsPath(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'A']]);
        $exporter = $this->exporter([[$this->submission(1, [
            ['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => 'v'],
        ])]]);
        $path = $exporter->export($form);
        $this->assertMatchesRegularExpression('#^export/furan_formbuilder/[A-Za-z0-9]+\.csv$#', $path);
        $this->assertSame($path, $this->openedPath);
        $this->assertSame('w', $this->openedMode);
        $this->assertSame(["\xEF\xBB\xBF"], $this->written);
        $this->assertTrue($this->closed);
        $this->assertContains(['submission_id', 'payload_json', 'context_json'], $this->selected);
        $this->assertCount(2, $this->rows);
    }

    public function testItRemovesThePartialExportFileWhenWritingFails(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'A']]);
        $exporter = $this->exporter([]);
        $this->failWrites = true;
        $this->deleted = [];

        try {
            $exporter->export($form);
            $this->fail('Expected the write failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertTrue($this->closed);
        $this->assertSame([$this->openedPath], $this->deleted);
    }

    public function testItKeepsFormulaNeutralisationForCellsAndHeaders(): void
    {
        $form = $this->form([['name' => 'a', 'label' => '=HEADER']]);
        $rows = $this->exportRows($this->exporter([[$this->submission(1, [
            ['name' => 'a', 'label' => 'x', 'type' => 'text', 'value' => '@cmd'],
        ])]]), $form);
        $this->assertSame("'=HEADER", $rows[0][6]);
        $this->assertSame("'@cmd", $rows[1][6]);
        foreach (['+1', '-1', "\tx", "\rx", "\nx"] as $v) {
            $rows = $this->exportRows($this->exporter([[$this->submission(1, [
                ['name' => 'a', 'label' => 'x', 'type' => 'text', 'value' => $v],
            ])]]), $form);
            $this->assertSame("'" . $v, $rows[1][6]);
        }
    }

    public function testItKeepsOneColumnPerFieldInSchemaOrderPlusOlderFields(): void
    {
        $form = $this->form([
            ['name' => 'last', 'label' => 'Last name'],
            ['name' => 'first', 'label' => 'First name'],
        ]);
        $rows = $this->exportRows($this->exporter([[$this->submission(5, [
            ['name' => 'first', 'label' => 'First name', 'type' => 'text', 'value' => 'Ann'],
            ['name' => 'last', 'label' => 'Last name', 'type' => 'text', 'value' => 'Lee'],
            ['name' => 'old', 'label' => 'Old label', 'type' => 'text', 'value' => 'x'],
        ])]]), $form);
        $this->assertSame(
            ['Submission ID', 'Created At', 'Store ID', 'Customer ID', 'Reference', 'Status', 'Last name', 'First name', 'Old label'],
            $rows[0]
        );
        $this->assertSame(['5', '2026-01-02 03:04:05', '1', '', self::REF, 'Answered', 'Lee', 'Ann', 'x'], $rows[1]);
    }

    public function testItJoinsMultiValuesAndUsesFileNames(): void
    {
        $form = $this->form([['name' => 'm', 'label' => 'M'], ['name' => 'f', 'label' => 'F']]);
        $row = $this->exportRows($this->exporter([[$this->submission(1, [
            ['name' => 'm', 'label' => 'M', 'type' => 'checkboxes', 'value' => ['a', 'b']],
            ['name' => 'f', 'label' => 'F', 'type' => 'file',
                'value' => ['file' => 'x/y.pdf', 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'application/pdf']],
        ])]]), $form)[1];
        $this->assertSame('a, b', $row[6]);
        $this->assertSame('cv.pdf', $row[7]);
    }

    public function testItPagesTheExportByLastSeenIdWithoutDuplicatingRows(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'A']]);
        $p = fn(int $id) => $this->submission($id, [['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => 'v']]);
        $rows = $this->exportRows($this->exporter([[$p(1), $p(2)], [$p(3)]]), $form);
        $this->assertSame(['1', '2', '3'], array_column(array_slice($rows, 1), 0));
        // Two passes (header discovery, then rows), each ending on an empty page.
        $this->assertSame([0, 2, 3, 0, 2, 3], $this->pagesRequested);
    }

    public function testItExportsMapAnswersAsSeparateLatitudeLongitudeAndLabelColumns(): void
    {
        $form = $this->form([['name' => 'm', 'label' => 'Where', 'type' => 'map'], ['name' => 'z', 'label' => 'Z', 'type' => 'text']]);
        $rows = $this->exportRows($this->exporter([
            [$this->submission(1, [
                ['name' => 'm', 'label' => 'Where', 'type' => 'map', 'value' => ['lat' => '51.500000', 'lng' => '-0.127800', 'zoom' => '', 'label' => '=Home']],
                ['name' => 'z', 'label' => 'Z', 'type' => 'text', 'value' => 'z'],
            ]), $this->submission(2, [])],
        ]), $form);

        $this->assertSame(['Where (latitude)', 'Where (longitude)', 'Where (label)', 'Z'], array_slice($rows[0], 6));
        $this->assertSame(['51.500000', '-0.127800', "'=Home", 'z'], array_slice($rows[1], 6));
        $this->assertSame(['', '', '', ''], array_slice($rows[2], 6));
    }

    public function testItExportsNegativeMapCoordinatesWithoutTheFormulaGuardPrefix(): void
    {
        $form = $this->form([['name' => 'm', 'label' => 'W', 'type' => 'map']]);
        $rows = $this->exportRows($this->exporter([[$this->submission(1, [
            ['name' => 'm', 'label' => 'W', 'type' => 'map', 'value' => ['lat' => '-33.868800', 'lng' => '-151.209300', 'zoom' => '', 'label' => '-x']],
            ['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => '-1'],
        ])]]), $form);

        $this->assertSame('-33.868800', $rows[1][6]);
        $this->assertSame('-151.209300', $rows[1][7]);
        $this->assertSame("'-x", $rows[1][8]);
        $this->assertSame("'-1", $rows[1][9]);
    }

    public function testItExportsAddressAnswersAsOneReadableCell(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'Addr', 'type' => 'address']]);
        $rows = $this->exportRows($this->exporter([[$this->submission(1, [
            ['name' => 'a', 'label' => 'Addr', 'type' => 'address', 'value' => [
                'street' => ['1 High St'], 'city' => 'Leeds', 'region_id' => '', 'region' => '', 'postcode' => 'LS1', 'country_id' => 'GB',
            ]],
        ])]]), $form);

        $this->assertSame('Addr', $rows[0][6]);
        $this->assertCount(7, $rows[1]);
        $this->assertStringStartsWith('1 High St, Leeds, LS1', $rows[1][6]);
    }

    public function testItExportsDateRangeAnswersAsSeparateStartAndEndColumns(): void
    {
        $form = $this->form([['name' => 'd', 'label' => 'Stay', 'type' => 'date_range']]);
        $rows = $this->exportRows($this->exporter([[
            $this->submission(1, [['name' => 'd', 'label' => 'Stay', 'type' => 'date_range', 'value' => ['start' => '2026-01-02', 'end' => '2026-01-09']]]),
            $this->submission(2, []),
        ]]), $form);

        $this->assertSame(['Stay (start)', 'Stay (end)'], array_slice($rows[0], 6));
        $this->assertSame(['2026-01-02', '2026-01-09'], array_slice($rows[1], 6));
        $this->assertSame(['', ''], array_slice($rows[2], 6));
    }

    public function testItExpandsRemovedStructuredFieldsByTheirPayloadType(): void
    {
        $form = $this->form([]);
        $rows = $this->exportRows($this->exporter([[$this->submission(1, [
            ['name' => 'old', 'label' => 'Old', 'type' => 'date_range', 'value' => ['start' => '2026-01-02', 'end' => '2026-01-09']],
        ])]]), $form);

        $this->assertSame(['Old (start)', 'Old (end)'], array_slice($rows[0], 6));
        $this->assertSame(['2026-01-02', '2026-01-09'], array_slice($rows[1], 6));
    }

    private const CONTEXT = [
        'id' => 5, 'sku' => '=SKU', 'name' => '-Shirt', 'url' => 'https://shop.test/a.html',
        'price' => 19.5, 'currency' => 'GBP',
    ];

    public function testItExportsProductIdSkuNameUrlPriceAndCurrencyColumnsWhenAnySubmissionHasProductContext(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'A', 'type' => 'text']]);
        $rows = $this->exportRows($this->exporter([[
            $this->submission(1, [['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => 'x']]),
            $this->submission(2, [['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => 'y']], self::CONTEXT),
        ]]), $form);

        $this->assertSame(
            ['A', 'Product ID', 'Product SKU', 'Product name', 'Product URL', 'Product price', 'Product currency'],
            array_slice($rows[0], 6)
        );
        $this->assertSame(['5', "'=SKU", "'-Shirt", 'https://shop.test/a.html', '19.5', 'GBP'], array_slice($rows[2], 7));
        $this->assertContains('payload_json', $this->selected[0] ?? []);
        $this->assertContains('context_json', $this->selected[0] ?? []);
    }

    public function testItLeavesTheProductCellsEmptyForSubmissionsWithoutProductContext(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'A', 'type' => 'text']]);
        $rows = $this->exportRows($this->exporter([[
            $this->submission(1, [['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => 'x']]),
            $this->submission(2, [], self::CONTEXT),
        ]]), $form);

        $this->assertSame(['x', '', '', '', '', '', ''], array_slice($rows[1], 6));
    }

    public function testItOmitsTheProductColumnsWhenNoSubmissionHasProductContext(): void
    {
        $form = $this->form([['name' => 'a', 'label' => 'A', 'type' => 'text']]);
        $rows = $this->exportRows($this->exporter([[$this->submission(1, [])]]), $form);

        $this->assertSame(['A'], array_slice($rows[0], 6));
    }

    public function testItKeepsTheFormulaGuardOnProductNameAndSkuCells(): void
    {
        $form = $this->form([]);
        $rows = $this->exportRows($this->exporter([[$this->submission(1, [], self::CONTEXT)]]), $form);

        $this->assertSame("'=SKU", $rows[1][7]);
        $this->assertSame("'-Shirt", $rows[1][8]);
    }

    public function testItWalksTheFormOnTheHintedKeysetIndexWithoutCountingTheRestOfTheForm(): void
    {
        $this->exporter([[$this->submission(1, [['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Ann']])]])
            ->export($this->form([['name' => 'name', 'label' => 'Name', 'type' => 'text']]));

        $this->assertStringContainsString(
            'FORCE INDEX (`K`) WHERE form_id = ? AND submission_id > ? ORDER BY submission_id LIMIT 500',
            $this->keysetSql
        );
    }

    public function testItExportsTheReferenceAsACsvColumn(): void
    {
        $rows = $this->exportRows(
            $this->exporter([[$this->submission(3, [['name' => 'a', 'value' => 'x']])]]),
            $this->form([['name' => 'a', 'label' => 'A', 'type' => 'text']])
        );

        $this->assertSame(['Submission ID', 'Created At', 'Store ID', 'Customer ID', 'Reference', 'Status', 'A'], $rows[0]);
        $this->assertSame(self::REF, $rows[1][4]);
    }
}
