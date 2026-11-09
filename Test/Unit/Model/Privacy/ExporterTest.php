<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Privacy;

use Furan\FormBuilder\Model\Privacy\AccountLookup;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Model\Privacy\Exporter;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ExporterTest extends TestCase
{
    private string $var;
    private string $uploads;
    private AdapterInterface&Stub $connection;
    private EmailHasher&Stub $hasher;
    private AccountLookup&Stub $accounts;
    /** @var string[] paths the storage was asked about */
    private array $asked = [];
    private Exporter $exporter;
    /** @var array<int, array> submissions served by the seek, keyed by bound value */
    private array $submissions = [];
    private array $messages = [];
    /** @var array<int, array> rows the recipient lookup returns */
    private array $recipientRows = [];
    private int $deliveryQueries = 0;
    /** @var list<array{string, array}> */
    private array $recipientQueries = [];
    /** @var array<int, array> */
    private array $seeks = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?AccountLookup $accounts = null, ?EmailHasher $hasher = null): void
    {
        $base = sys_get_temp_dir() . '/ffb_export_' . bin2hex(random_bytes(6));
        $this->var = $base . '/var';
        $this->uploads = $base . '/import_export';
        mkdir($this->uploads . '/furan_formbuilder/1/2026/01', 0777, true);
        mkdir($this->var, 0777, true);

        $write = $this->createStub(WriteInterface::class);
        $write->method('getAbsolutePath')->willReturnCallback(fn (?string $p = null) => $this->var . '/' . $p);
        $write->method('create')->willReturnCallback(function (string $p): bool {
            is_dir($this->var . '/' . $p) || mkdir($this->var . '/' . $p, 0777, true);
            return true;
        });
        $read = $this->createMock(ReadInterface::class);
        $read->method('getAbsolutePath')->willReturnCallback(
            fn (?string $p = null) => $p === null ? $this->uploads : $this->uploads . '/' . $p
        );
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($write);
        $filesystem->method('getDirectoryRead')->willReturn($read);

        $this->connection = $this->createStub(AdapterInterface::class);
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind): array {
            if (str_contains($sql, 'FROM furan_formbuilder_submission')) {
                $this->seeks[] = [$sql, $bind];
                return array_values(array_filter(
                    $this->submissions[$bind[0]] ?? [],
                    static fn (array $r): bool => (int)$r['submission_id'] > $bind[1]
                ));
            }
            if (str_contains($sql, 'furan_formbuilder_webhook_delivery')) {
                $this->deliveryQueries++;
                return [];
            }
            if (str_contains($sql, 'FROM furan_formbuilder_webhook_recipient')) {
                $this->recipientQueries[] = [$sql, $bind];
                return $this->recipientRows;
            }
            if (str_contains($sql, 'FROM furan_formbuilder_form')) {
                return [['form_id' => '1', 'identifier' => 'contact']];
            }
            return $this->messages;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->hasher = $hasher ?? $this->createStub(EmailHasher::class);
        $this->accounts = $accounts ?? $this->createStub(AccountLookup::class);
        // Stands in for remote storage: the exporter may only reach uploads through these two calls.
        $storage = $this->createStub(FileStorage::class);
        $storage->method('downloadPath')->willReturnCallback(function (string $path): string {
            $this->asked[] = $path;
            if (!is_file($this->uploads . '/' . $path)) {
                throw new LocalizedException(__('The requested file does not exist.'));
            }
            return $path;
        });
        $storage->method('copyToLocal')->willReturnCallback(function (string $path, string $local): void {
            copy($this->uploads . '/' . $path, $local);
        });
        $read->expects($this->never())->method('getAbsolutePath');
        $this->exporter = new Exporter($resource, $this->hasher, $filesystem, $storage, new ValueFormatter(FieldPools::withFiles()), $this->accounts, FieldPools::withFiles(), new SubmissionStatus());
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname($this->var), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(dirname($this->var));
    }

    private function row(int $id, array $payload, string $context = ''): array
    {
        return ['submission_id' => (string)$id, 'form_id' => '1', 'store_id' => '1',
            'created_at' => '2026-01-0' . $id . ' 00:00:00', 'status' => '0',
            'payload_json' => json_encode($payload), 'context_json' => $context,
            'reference' => hex2bin('0194f3a0111172228333444455556666')];
    }

    private function upload(string $name, string $contents): string
    {
        $relative = 'furan_formbuilder/1/2026/01/' . $name;
        file_put_contents($this->uploads . '/' . $relative, $contents);
        return $relative;
    }

    private function fileAnswer(string $path, string $name = 'cv.pdf'): array
    {
        return ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => ['file' => $path, 'name' => $name]];
    }

    private function open(string $relative): \ZipArchive
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->var . '/' . $relative));
        return $zip;
    }

    public function testItExportsEverySubmissionForACustomerInIdOrder(): void
    {
        $text = ['name' => 'msg', 'label' => 'Message', 'type' => 'text', 'value' => 'Hello'];
        $this->submissions[7] = [$this->row(1, [$text]), $this->row(2, [$text], '{"sku":"A1"}')];
        $this->messages = [['submission_id' => '2', 'author' => 'customer', 'body' => 'Thanks', 'created_at' => 'x']];

        $zip = $this->open($this->exporter->exportCustomer(7));
        $data = json_decode($zip->getFromName('data.json'), true);

        $this->assertSame([1, 2], array_column($data['submissions'], 'id'));
        $this->assertSame('contact', $data['submissions'][0]['form']);
        $this->assertSame('Hello', $data['submissions'][0]['answers'][0]['value']);
        $this->assertSame(['sku' => 'A1'], $data['submissions'][1]['context']);
        $this->assertSame('Thanks', $data['submissions'][1]['messages'][0]['body']);
        $this->assertSame([7, 0], $this->seeks[0][1]);
        $this->assertStringContainsString('customer_id = ? AND submission_id > ?', $this->seeks[0][0]);
        $this->assertSame([], $data['missing_files']);
    }

    public function testItExportsGuestSubmissionsFoundByAnyEmailFingerprintKeyVersion(): void
    {
        $hasher = $this->createMock(EmailHasher::class);
        $this->fixture(hasher: $hasher);
        $hasher->method('candidates')->with('a@b.c')->willReturn([0 => 'h0', 1 => 'h1']);
        $this->submissions['h0'] = [$this->row(1, [])];
        $this->submissions['h1'] = [$this->row(4, [])];

        $zip = $this->open($this->exporter->exportEmail('a@b.c'));
        $data = json_decode($zip->getFromName('data.json'), true);

        $this->assertSame([1, 4], array_column($data['submissions'], 'id'));
        $this->assertStringContainsString('email_hash = ? AND submission_id > ?', $this->seeks[0][0]);
    }

    public function testItPacksTheExportDataAndTheUploadedFilesIntoOneZipStreamedFromDisk(): void
    {
        $first = $this->upload(str_repeat('a', 32), 'PDF-ONE');
        $second = $this->upload(str_repeat('b', 32), 'PDF-TWO');
        $this->submissions[7] = [
            $this->row(1, [$this->fileAnswer($first, '../CV 1?.pdf'), $this->fileAnswer($second, '../CV 1?.pdf')]),
        ];

        $relative = $this->exporter->exportCustomer(7);
        $zip = $this->open($relative);

        $this->assertSame('PDF-ONE', $zip->getFromName('files/1/CV 1_.pdf'));
        $this->assertSame('PDF-TWO', $zip->getFromName('files/1/CV 1__1.pdf'));
        $data = json_decode($zip->getFromName('data.json'), true);
        $this->assertSame('../CV 1?.pdf', $data['submissions'][0]['answers'][0]['value']);
        $this->assertStringContainsString('CV: ../CV 1?.pdf', $zip->getFromName('data.csv'));
        $this->assertSame([basename($relative)], array_values(array_diff(
            scandir($this->var . '/export/furan_formbuilder'),
            ['.', '..']
        )));
    }

    public function testItSkipsUploadedFilesMissingFromDiskAndListsThemInTheExport(): void
    {
        $this->submissions[7] = [$this->row(3, [
            $this->fileAnswer('furan_formbuilder/1/2026/01/' . str_repeat('c', 32), 'gone.pdf'),
            $this->fileAnswer('furan_formbuilder/../../outside', 'escape.pdf'),
        ])];
        file_put_contents(dirname($this->uploads) . '/outside', 'SECRET');

        $zip = $this->open($this->exporter->exportCustomer(7));
        $data = json_decode($zip->getFromName('data.json'), true);

        $this->assertSame(
            [['submission_id' => 3, 'name' => 'gone.pdf'], ['submission_id' => 3, 'name' => 'escape.pdf']],
            $data['missing_files']
        );
        $this->assertFalse($zip->getFromName('files/3/gone.pdf'));
        $this->assertFalse($zip->getFromName('files/3/escape.pdf'));
        $this->assertNotContains('furan_formbuilder/../../outside', $this->asked);
    }

    public function testItAlsoExportsTheAccountsRegisteredWithTheEmailWithoutDuplicates(): void
    {
        $accounts = $this->createMock(AccountLookup::class);
        $this->fixture(accounts: $accounts);
        $accounts->method('customerIds')->with('a@b.c')->willReturn([7]);
        $this->hasher->method('candidates')->willReturn([0 => 'h0']);
        $this->submissions[7] = [$this->row(1, []), $this->row(2, [])];
        $this->submissions['h0'] = [$this->row(2, []), $this->row(5, [])];

        $zip = $this->open($this->exporter->exportEmail('a@b.c'));
        $data = json_decode($zip->getFromName('data.json'), true);

        $this->assertSame([1, 2, 5], array_column($data['submissions'], 'id'));
        $this->assertStringContainsString('customer_id = ? AND submission_id > ?', $this->seeks[0][0]);
    }

    public function testItGuardsSpreadsheetFormulasInTheCsv(): void
    {
        $this->submissions[7] = [$this->row(1, [
            ['name' => 'n', 'label' => '=HYPERLINK("x")', 'type' => 'text', 'value' => 'v'],
        ])];

        $zip = $this->open($this->exporter->exportCustomer(7));

        $this->assertStringContainsString("\"'=HYPERLINK", $zip->getFromName('data.csv'));
    }

    public function testItWritesStructuredAnswersAsReadableTextInTheCsv(): void
    {
        $this->submissions[7] = [$this->row(1, [
            ['name' => 'a', 'label' => 'Address', 'type' => 'address', 'value' => [
                'street' => ['1 Main St'], 'city' => 'Austin', 'region' => 'Texas', 'postcode' => '78701', 'country_id' => 'US',
            ]],
            ['name' => 'd', 'label' => 'Stay', 'type' => 'date_range', 'value' => ['start' => '2026-12-10', 'end' => '2026-12-14']],
        ])];

        $csv = $this->open($this->exporter->exportCustomer(7))->getFromName('data.csv');

        $this->assertStringContainsString('Address: 1 Main St, Austin, Texas, 78701', $csv);
        $this->assertStringContainsString('Stay: 2026-12-10', $csv);
        $this->assertStringNotContainsString('"street"', $csv);
    }

    public function testItPacksFilesOfACustomFileTypeIntoTheExport(): void
    {
        $path = $this->upload(str_repeat('c', 32), 'DOC-ONE');
        $entry = ['type' => 'doc'] + $this->fileAnswer($path, 'plan.pdf');
        $this->submissions[7] = [$this->row(1, [$entry])];

        $zip = $this->open($this->exporter->exportCustomer(7));

        $this->assertSame('DOC-ONE', $zip->getFromName('files/1/plan.pdf'));
    }

    public function testItIncludesTheReferenceInThePrivacyExport(): void
    {
        $text = ['name' => 'msg', 'label' => 'Message', 'type' => 'text', 'value' => 'Hello'];
        $this->submissions[7] = [$this->row(1, [$text])];

        $zip = $this->open($this->exporter->exportCustomer(7));

        $data = json_decode($zip->getFromName('data.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $data['submissions'][0]['reference']);
        $csv = (string)$zip->getFromName('data.csv');
        $this->assertStringContainsString('Reference', strtok(substr($csv, 3), "\n"));
        $this->assertStringContainsString('0194f3a0-1111-7222-8333-444455556666', $csv);
        $this->assertStringContainsString('SELECT submission_id, reference,', $this->seeks[0][0]);
    }

    public function testItWritesTheStatusAsACodeAndALabel(): void
    {
        $text = ['name' => 'msg', 'label' => 'Message', 'type' => 'text', 'value' => 'Hello'];
        $answered = $this->row(1, [$text]);
        $answered['status'] = '2';
        $this->submissions[7] = [$answered];

        $zip = $this->open($this->exporter->exportCustomer(7));

        $item = json_decode($zip->getFromName('data.json'), true, 512, JSON_THROW_ON_ERROR)['submissions'][0];
        $this->assertSame('answered', $item['status']);
        $this->assertSame('Answered', $item['status_label']);
        $csv = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr((string)$zip->getFromName('data.csv'), 3))));
        $this->assertSame('Answered', $csv[1][5]);
    }

    public function testItListsRecipientsInThePrivacyExportAfterDeliveriesArePurged(): void
    {
        $this->submissions[7] = [$this->row(1, []), $this->row(2, [])];
        $this->recipientRows = [
            ['endpoint_id' => '3', 'host' => 'crm.example.com', 'name' => 'CRM'],
            ['endpoint_id' => '3', 'host' => 'crm.example.com', 'name' => 'CRM'],
        ];

        $zip = $this->open($this->exporter->exportCustomer(7));
        $raw = $zip->getFromName('data.json');
        $data = json_decode($raw, true);

        $this->assertSame([['name' => 'CRM', 'host' => 'crm.example.com']], $data['webhook_recipients']);
        $this->assertCount(1, $this->recipientQueries);
        $this->assertStringContainsString('submission_id IN (1,2)', $this->recipientQueries[0][0]);
        $this->assertStringNotContainsString('url', $this->recipientQueries[0][0]);
        $this->assertSame(0, $this->deliveryQueries);
    }

    public function testItShowsTheStoredHostWhenTheEndpointWasDeleted(): void
    {
        $this->submissions[7] = [$this->row(1, [])];
        $this->recipientRows = [['endpoint_id' => '4', 'host' => 'old.example.com', 'name' => null]];

        $zip = $this->open($this->exporter->exportCustomer(7));

        $this->assertSame(
            [['name' => 'Deleted endpoint', 'host' => 'old.example.com']],
            json_decode($zip->getFromName('data.json'), true)['webhook_recipients']
        );
    }

    public function testItExportsNoWebhookRecipientsWhenNothingWasDelivered(): void
    {
        $this->submissions[7] = [$this->row(1, [])];

        $zip = $this->open($this->exporter->exportCustomer(7));

        $this->assertSame([], json_decode($zip->getFromName('data.json'), true)['webhook_recipients']);
    }
}
