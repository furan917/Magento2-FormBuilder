<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Api\Data\SubmissionInterfaceFactory;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Submission\NotEditableException;
use Furan\FormBuilder\Model\Submission\SubmissionUpdater;
use Furan\FormBuilder\Model\Submission\SummaryBuilder;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionUpdaterTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private Journal&Stub $journal;
    private FileRegistry&Stub $registry;
    private ManagerInterface&Stub $events;
    private Json $json;
    private \Furan\FormBuilder\Model\Webhook\SubmissionDeliveries&Stub $deliveries;
    private bool $inTransaction = false;
    private SubmissionStub $stored;
    private array $locked;
    private string $fileType = 'file';
    private ?\Furan\FormBuilder\Model\FieldType\Pool $pool = null;
    private string $serverType = 'hidden';
    private \Magento\Customer\Model\Session&Stub $session;

    protected function setUp(): void
    {
        $this->json = new Json();
        $this->session = $this->createStub(\Magento\Customer\Model\Session::class);
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->journal = $this->createStub(Journal::class);
        $this->events = $this->createStub(ManagerInterface::class);
        $this->registry = $this->configureRegistry($this->createStub(FileRegistry::class));
        $this->deliveries = $this->createStub(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->stored = new SubmissionStub();
        $this->locked = [
            'form_id' => 7, 'customer_id' => 5, 'status' => 0, 'store_id' => null, 'customer_group_id' => 3,
            'created_at' => '2026-11-01 10:00:00',
            'payload_json' => $this->json->serialize([
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Old'],
                ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => $this->file('old')],
                ['name' => 'ref', 'label' => 'Ref', 'type' => 'hidden', 'value' => 'utm-1'],
            ]),
        ];
    }

    private function file(string $name): array
    {
        return ['file' => 'furan_formbuilder/7/2026/11/' . $name, 'name' => $name . '.pdf', 'size' => 3, 'mime' => 'application/pdf'];
    }

    private function updater(): SubmissionUpdater
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $factory = $this->createStub(SubmissionInterfaceFactory::class);
        $factory->method('create')->willReturn($this->stored);

        return new SubmissionUpdater(
            $resource, $this->json, $this->journal, $this->registry, new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))), $factory, $this->events,
            $this->createStub(\Psr\Log\LoggerInterface::class),
            new \Furan\FormBuilder\Model\Customer\Impersonation($this->session),
            $this->pool ?? FieldPools::withFiles(),
            $this->deliveries,
            new \Furan\FormBuilder\Model\Account\SubmissionPolicy()
        );
    }

    private function form(): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);

        return $form;
    }

    private function fields(): array
    {
        return [
            ['kind' => 'field', 'type' => 'text', 'name' => 'name', 'label' => 'Name'],
            ['kind' => 'field', 'type' => $this->fileType, 'name' => 'cv', 'label' => 'CV'],
            ['kind' => 'field', 'type' => $this->serverType, 'name' => 'ref', 'label' => 'Ref'],
        ];
    }

    private function edit(array $payload, array $newPaths = [], array $values = ['name' => 'New'], int $owner = 5): array
    {
        $this->connection->method('fetchRow')->willReturn($this->locked);
        $this->updater()->update(
            $this->form(),
            9,
            $owner,
            $this->fields(),
            $values,
            $payload,
            $newPaths
        );

        return $payload;
    }

    private function textEntry(string $value = 'New'): array
    {
        return ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => $value];
    }

    /**
     * @template T of FileRegistry&Stub
     * @param T $registry
     * @return T
     */
    private function configureRegistry(FileRegistry&Stub $registry): FileRegistry&Stub
    {
        $registry->method('saveWith')->willReturnCallback(function (array $paths, callable $save): void {
            $this->inTransaction = true;
            try {
                $this->registry->attach($paths, $save());
            } finally {
                $this->inTransaction = false;
            }
        });
        return $registry;
    }

    public function testItRefusesWhileAnAdminIsLoggedInAsTheCustomerBeforeLockingTheRow(): void
    {
        $registry = $this->registry = $this->configureRegistry($this->createMock(FileRegistry::class));
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $session = $this->session = $this->createMock(\Magento\Customer\Model\Session::class);
        $session->method('getData')->with('logged_as_customer_admind_id')->willReturn(3);
        $connection->expects($this->never())->method('fetchRow');
        $registry->expects($this->never())->method('saveWith');

        try {
            $this->edit([$this->textEntry()]);
            $this->fail('Expected a refusal.');
        } catch (NotEditableException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame(
                'Survey answers cannot be submitted or changed while you are logged in as this customer.',
                $e->getMessage()
            );
        }
    }

    public function testItUpdatesTheExistingSubmissionInsteadOfCreatingAnother(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('insert');
        $connection->expects($this->once())->method('update')->with(
            'furan_formbuilder_submission',
            $this->callback(fn(array $data): bool => array_keys($data) === ['payload_json', 'summary', 'updated_at']
                && $data['summary'] === 'New'
                && $this->json->unserialize($data['payload_json'])[0]['value'] === 'New'
                && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $data['updated_at']) === 1),
            ['submission_id = ?' => 9]
        );

        $this->edit([$this->textEntry()]);
    }

    public function testItKeepsTheReferenceWhenASubmissionIsEdited(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(static fn (array $data): bool => !array_key_exists('reference', $data)),
            $this->anything()
        );

        $this->edit([$this->textEntry()]);

        $this->assertSame('', $this->stored->getReference(), 'The updater never generates one');
    }

    #[DataProvider('lockedRowProvider')]
    public function testItRefusesEditsOnceTheSubmissionIsAnsweredOrClosed(array $change): void
    {
        $events = $this->events = $this->createMock(ManagerInterface::class);
        $journal = $this->journal = $this->createMock(Journal::class);
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $this->locked = $change + $this->locked;
        $connection->expects($this->never())->method('update');
        $journal->expects($this->never())->method('replace');
        $events->expects($this->never())->method('dispatch');
        $this->expectException(NotEditableException::class);

        $this->edit([$this->textEntry()]);
    }

    public static function lockedRowProvider(): array
    {
        return [
            'answered' => [['status' => 2]],
            'closed' => [['status' => 3]],
            'other owner' => [['customer_id' => 6]],
            'guest row' => [['customer_id' => null]],
            'other form' => [['form_id' => 8]],
        ];
    }

    public function testItRefusesWhenTheRowIsGone(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('fetchRow')->willReturn(false);
        $connection->expects($this->never())->method('update');
        $this->expectException(NotEditableException::class);

        $this->updater()->update(
            $this->form(), 9, 5, $this->fields(), [], [], []
        );
    }

    public function testItJournalsTheChangeAsAReplacement(): void
    {
        $journal = $this->journal = $this->createMock(Journal::class);
        $new = [$this->textEntry()];
        $journal->expects($this->once())->method('replace')->with(
            $this->callback(fn($s): bool => $s === $this->stored
                && $s->getFormId() === 7
                && $s->getStoreId() === 0
                && $s->getCustomerGroupId() === 3
                && $s->getCreatedAt() === '2026-11-01 10:00:00'
                && $s->getPayload()[0]['value'] === 'Old'),
            $this->callback(fn(array $p): bool => $p[0]['value'] === 'New')
        );

        $this->edit($new);
    }

    public function testItKeepsTheStoredFileWhenARequiredFileFieldIsLeftEmptyOnEdit(): void
    {
        $registry = $this->registry = $this->configureRegistry($this->createMock(FileRegistry::class));
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(function (array $data): bool {
                $payload = array_column($this->json->unserialize($data['payload_json']), null, 'name');
                return ($payload['cv']['value']['file'] ?? '') === 'furan_formbuilder/7/2026/11/old';
            }),
            $this->anything()
        );
        $registry->expects($this->never())->method('detach');

        $this->edit([$this->textEntry()]);
    }

    public function testItKeepsTheStoredFileWhenTheEditPostsAnEmptyFileEntry(): void
    {
        $registry = $this->registry = $this->configureRegistry($this->createMock(FileRegistry::class));
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(function (array $data): bool {
                $payload = array_column($this->json->unserialize($data['payload_json']), null, 'name');
                return ($payload['cv']['value']['file'] ?? '') === 'furan_formbuilder/7/2026/11/old';
            }),
            $this->anything()
        );
        $registry->expects($this->never())->method('detach');

        $this->edit([$this->textEntry(), ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => '']]);
    }

    public function testItReleasesAReplacedFileToTheCleanupCron(): void
    {
        $registry = $this->registry = $this->configureRegistry($this->createMock(FileRegistry::class));
        $new = $this->file('new');
        $registry->expects($this->once())->method('detach')->with(9, [$this->file('old')['file']]);
        $registry->expects($this->once())->method('attach')->with([$new['file']], 9);

        $this->edit([$this->textEntry(), ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => $new]], [$new['file']]);
    }

    public function testItReChecksTheStatusUnderTheRowLock(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchRow')
            ->with($this->stringContains('FOR UPDATE'), [9])
            ->willReturn($this->locked);

        $this->updater()->update(
            $this->form(), 9, 5, $this->fields(), ['name' => 'New'], [$this->textEntry()], []
        );
    }

    public function testItDoesNotResendNotificationEmailsForAnEdit(): void
    {
        $events = $this->events = $this->createMock(ManagerInterface::class);
        $events->expects($this->once())->method('dispatch')->with(
            'furan_formbuilder_submission_updated',
            $this->callback(fn(array $d): bool => array_keys($d) === ['form', 'submission', 'values']
                && $d['submission'] === $this->stored && $d['values'] === ['name' => 'New'])
        );

        $this->edit([$this->textEntry()]);
    }

    public function testItDispatchesSubmissionUpdatedWithTheSubmissionIdCustomerIdAndStatus(): void
    {
        $events = $this->events = $this->createMock(ManagerInterface::class);
        $this->locked['status'] = 1;
        $events->expects($this->once())->method('dispatch')->with(
            'furan_formbuilder_submission_updated',
            $this->callback(fn(array $d): bool => $d['submission']->getId() === 9
                && $d['submission']->getCustomerId() === 5
                && $d['submission']->getStatus() === 1)
        );

        $this->edit([$this->textEntry()]);
    }

    public function testItKeepsStoredHiddenFieldValuesOnEdit(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(function (array $data): bool {
                $payload = array_column($this->json->unserialize($data['payload_json']), null, 'name');
                return $payload['ref']['value'] === 'utm-1';
            }),
            $this->anything()
        );

        $this->edit([$this->textEntry(), ['name' => 'ref', 'label' => 'Ref', 'type' => 'hidden', 'value' => 'tampered']]);
    }

    public function testItKeepsStoredValuesOfAnyServerValueTypeOnEdit(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $this->serverType = \Furan\FormBuilder\Test\Unit\Helper\StampType::CODE;
        $this->pool = new \Furan\FormBuilder\Model\FieldType\Pool(
            FieldPools::withFiles()->all() + [$this->serverType => new \Furan\FormBuilder\Test\Unit\Helper\StampType()]
        );
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(function (array $data): bool {
                $payload = array_column($this->json->unserialize($data['payload_json']), null, 'name');
                return $payload['ref']['value'] === 'utm-1';
            }),
            $this->anything()
        );

        $this->edit([$this->textEntry(), ['name' => 'ref', 'label' => 'Ref', 'type' => 'stamp', 'value' => 'tampered']]);
    }

    public function testItKeepsTheStoredFileOfACustomFileTypeWhenNoNewUploadIsPosted(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $this->fileType = 'doc';
        $this->locked['payload_json'] = str_replace('"type":"file"', '"type":"doc"', $this->locked['payload_json']);
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(function (array $data): bool {
                $payload = array_column($this->json->unserialize($data['payload_json']), null, 'name');
                return ($payload['cv']['value']['file'] ?? '') === 'furan_formbuilder/7/2026/11/old';
            }),
            $this->anything()
        );

        $this->edit([$this->textEntry()]);
    }

    public function testItKeepsTheStoredNewsletterAnswerWhenTheStoreHasNewslettersOff(): void
    {
        $connection = $this->connection = $this->createMock(AdapterInterface::class);
        $bridge = $this->createStub(\Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturnCallback(static fn (?int $store): bool => $store !== 2);
        $this->pool = new \Furan\FormBuilder\Model\FieldType\Pool(
            FieldPools::withFiles()->all() + ['newsletter' => new \Furan\FormBuilder\Model\FieldType\Newsletter($bridge)]
        );
        $this->locked['store_id'] = 2;
        $this->locked['payload_json'] = $this->json->serialize([
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Old'],
            ['name' => 'news', 'label' => 'News', 'type' => 'newsletter', 'value' => 'yes'],
        ]);
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(function (array $data): bool {
                $payload = array_column($this->json->unserialize($data['payload_json']), null, 'name');
                return ($payload['news']['value'] ?? '') === 'yes' && $payload['name']['value'] === 'New';
            }),
            $this->anything()
        );
        $this->connection->method('fetchRow')->willReturn($this->locked);

        $this->updater()->update(
            $this->form(), 9, 5,
            [
                ['kind' => 'field', 'type' => 'text', 'name' => 'name', 'label' => 'Name'],
                ['kind' => 'field', 'type' => 'newsletter', 'name' => 'news', 'label' => 'News'],
            ],
            ['name' => 'New'],
            [$this->textEntry()],
            []
        );
    }

    public function testItWritesDeliveriesInsideTheEditTransaction(): void
    {
        $deliveries = $this->deliveries = $this->createMock(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->locked['store_id'] = 2;
        $this->connection->method('update')->willReturnCallback(function (): int {
            $this->assertTrue($this->inTransaction);
            return 1;
        });
        $deliveries->expects($this->once())->method('record')
            ->willReturnCallback(function (int $id, int $formId, ?int $storeId, string $event): array {
                $this->assertTrue($this->inTransaction);
                $this->assertSame([9, 7, 2, 'submission.updated'], [$id, $formId, $storeId, $event]);
                return [55];
            });
        $deliveries->expects($this->once())->method('wake')->with([55])
            ->willReturnCallback(function (): void {
                $this->assertFalse($this->inTransaction, 'woken before commit');
            });

        $this->edit([$this->textEntry()]);
    }

    public function testItEnqueuesNothingWhenTheEditIsRefused(): void
    {
        $deliveries = $this->deliveries = $this->createMock(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->locked['status'] = 2;
        $deliveries->expects($this->never())->method('record');
        $deliveries->expects($this->never())->method('wake');
        $this->expectException(NotEditableException::class);

        $this->edit([$this->textEntry()]);
    }

    public function testItPassesANullStoreWhenTheSubmissionHasNoStore(): void
    {
        $deliveries = $this->deliveries = $this->createMock(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $deliveries->expects($this->once())->method('record')
            ->with(9, 7, null, 'submission.updated')->willReturn([]);

        $this->edit([$this->textEntry()]);
    }
}
