<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Privacy;

use Furan\FormBuilder\Api\Data\SubmissionInterfaceFactory;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Model\Privacy\Anonymiser;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Schema\UnsupportedSchemaVersionException;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Submission\SummaryBuilder;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Test\Unit\Model\Submission\SubmissionStub;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AnonymiserTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private Journal&Stub $journal;
    private FileRegistry&Stub $files;
    private Json $json;
    /** @var array<int, array{string, array, array}> table, data, where */
    private array $updates = [];
    private array $deletes = [];
    private array $seeks = [];
    private array $replaced = [];
    private array $transactions = [];
    private SeriesRegistry&Stub $registry;
    private SchemaMigrator&Stub $migrator;
    private LoggerInterface&Stub $logger;

    protected function setUp(): void
    {
        $this->json = new Json();
        $this->migrator = $this->createStub(SchemaMigrator::class);
        $this->migrator->method('upgrade')->willReturnArgument(0);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->journal = $this->createStub(Journal::class);
        $this->files = $this->createStub(FileRegistry::class);
        $this->connection->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [$table, $data, $where];
            return 1;
        });
        $this->connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = [$table, $where];
            return 1;
        });
        $this->journal->method('replace')->willReturnCallback(function ($stored, array $new, ?array $specs = null): void {
            $this->replaced[] = [$stored, $stored->getPayload(), $new, $specs];
        });
        $this->registry = $this->createStub(SeriesRegistry::class);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $this->connection->method($method)->willReturnCallback(function () use ($method) {
                $this->transactions[] = $method;
                return $this->connection;
            });
        }
    }

    private function schema(): string
    {
        return $this->json->serialize(['pages' => [['items' => [
            ['kind' => 'field', 'name' => 'email', 'type' => 'email'],
            ['kind' => 'field', 'name' => 'colour', 'type' => 'text', 'personal' => false],
            ['kind' => 'field', 'name' => 'cv', 'type' => 'file'],
            ['kind' => 'section', 'items' => [['kind' => 'field', 'name' => 'note', 'type' => 'text', 'personal' => false]]],
        ]]]]);
    }

    private function row(int $id, array $payload, int $formId = 7): array
    {
        return [
            'submission_id' => $id, 'form_id' => $formId, 'created_at' => '2026-11-01 10:00:00',
            'store_id' => null, 'customer_group_id' => 3, 'payload_json' => $this->json->serialize($payload),
        ];
    }

    private function payload(): array
    {
        return [
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'a@b.test'],
            ['name' => 'colour', 'label' => 'Colour', 'type' => 'text', 'value' => 'red'],
            ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => ['file' => 'ffb/7/cv.pdf', 'name' => 'cv.pdf']],
            ['name' => 'note', 'label' => 'Note', 'type' => 'text', 'value' => 'hi'],
        ];
    }

    /**
     * @param array<int, array> $rows locked rows served for every batch
     * @param int[]|null $ids pending submission ids, the rows' ids by default
     */
    private function anonymise(array $rows, ?array $ids = null): void
    {
        $this->connection->method('fetchAll')->willReturnCallback(function ($sql) use ($rows) {
            if (str_contains((string)$sql, 'schema_json')) {
                return [['form_id' => 7, 'schema_json' => $this->schema()]];
            }
            $this->seeks[] = (string)$sql;
            return $rows;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $factory = $this->createStub(SubmissionInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new SubmissionStub());
        (new Anonymiser(
            $resource, $this->json, $this->journal, $this->files, new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), new \Furan\FormBuilder\Model\Submission\StoreDisplayContext($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))), new PersonalData(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()),
            $factory, $this->registry, $this->migrator, $this->logger
        ))->anonymise($ids ?? array_column($rows, 'submission_id'));
    }

    private function submissionUpdate(): array
    {
        foreach ($this->updates as [$table, $data, $where]) {
            if ($table === 'furan_formbuilder_submission') {
                return [$data, $where];
            }
        }
        $this->fail('no submission update');
    }

    public function testItAnonymisesPersonalFieldsByDefaultWhenAnAccountIsDeleted(): void
    {
        $files = $this->files = $this->createMock(FileRegistry::class);
        $this->anonymise([$this->row(1, $this->payload())]);

        [$data] = $this->submissionUpdate();
        $values = array_column($this->json->unserialize($data['payload_json']), 'value', 'name');
        $this->assertSame('', $values['email']);
        $this->assertSame('', $values['cv']);
        $files->expects($this->never())->method('remove');
    }

    public function testItUnlinksTheFilesOfBlankedFileFields(): void
    {
        $files = $this->files = $this->createMock(FileRegistry::class);
        $files->expects($this->once())->method('detach')->with(1, ['ffb/7/cv.pdf']);
        $this->anonymise([$this->row(1, $this->payload())]);
    }

    public function testItKeepsNonPersonalAnswersWhenAnonymising(): void
    {
        $this->anonymise([$this->row(1, $this->payload())]);

        [$data] = $this->submissionUpdate();
        $values = array_column($this->json->unserialize($data['payload_json']), 'value', 'name');
        $this->assertSame('red', $values['colour']);
        $this->assertSame('hi', $values['note']);
    }

    public function testItBlanksEntriesWhoseFieldIsGoneFromTheSchema(): void
    {
        $payload = $this->payload();
        $payload[] = ['name' => 'old', 'label' => 'Old', 'type' => 'text', 'value' => 'secret'];
        $this->anonymise([$this->row(1, $payload)]);

        [$data] = $this->submissionUpdate();
        $values = array_column($this->json->unserialize($data['payload_json']), 'value', 'name');
        $this->assertSame('', $values['old']);
    }

    public function testItRecomputesTheSummaryFromTheAnonymisedAnswers(): void
    {
        $this->anonymise([$this->row(1, $this->payload())]);

        [$data] = $this->submissionUpdate();
        $this->assertSame('red · hi', $data['summary']);
    }

    public function testItClearsTheIpAddressAndEmailHashWhenAnonymising(): void
    {
        $this->anonymise([$this->row(1, $this->payload())]);

        [$data, $where] = $this->submissionUpdate();
        $this->assertArrayHasKey('remote_ip', $data);
        $this->assertNull($data['remote_ip']);
        $this->assertNull($data['email_hash']);
        $this->assertNull($data['email_hash_version']);
        $this->assertSame(['submission_id = ?' => 1], $where);
    }

    public function testItDeletesTheRespondentRowsOfAnonymisedSubmissions(): void
    {
        $this->anonymise([$this->row(1, $this->payload()), $this->row(2, $this->payload())]);

        $this->assertContains(['furan_formbuilder_respondent', ['submission_id IN (?)' => [1, 2]]], $this->deletes);
    }

    public function testItJournalsAnonymisedAnswersAsAReplacement(): void
    {
        $this->anonymise([$this->row(1, $this->payload())]);

        $this->assertCount(1, $this->replaced);
        [$stored, $old, $new] = $this->replaced[0];
        $this->assertSame('a@b.test', $old[0]['value']);
        $this->assertSame('', $new[0]['value']);
        $this->assertSame(7, $stored->getFormId());
        $this->assertSame('2026-11-01 10:00:00', $stored->getCreatedAt());
        $this->assertSame(3, $stored->getCustomerGroupId());
    }

    public function testItBlanksTheCustomersOwnThreadMessagesWhenAnonymising(): void
    {
        $this->anonymise([$this->row(1, $this->payload()), $this->row(2, $this->payload())]);

        $found = array_values(array_filter(
            $this->updates,
            static fn (array $u): bool => $u[0] === 'furan_formbuilder_message'
        ));
        $this->assertCount(1, $found);
        $this->assertSame(['body' => ''], $found[0][1]);
        $this->assertSame(['submission_id IN (?)' => [1, 2], 'author = ?' => 'customer'], $found[0][2]);
    }

    public function testItLocksAndAnonymisesOnlySubmissionsStillUnlinkedInBatches(): void
    {
        $this->anonymise([], range(1, 501));

        $this->assertCount(2, $this->seeks);
        $this->assertStringContainsString('WHERE submission_id IN (1,', $this->seeks[0]);
        $this->assertStringContainsString(',500) AND customer_id IS NULL FOR UPDATE', $this->seeks[0]);
        $this->assertStringContainsString('WHERE submission_id IN (501) AND customer_id IS NULL FOR UPDATE', $this->seeks[1]);
        $this->assertSame(['beginTransaction', 'commit', 'beginTransaction', 'commit'], $this->transactions);
    }

    public function testItDoesNothingWithoutSubmissions(): void
    {
        $this->anonymise([], []);

        $this->assertSame([], $this->transactions);
        $this->assertSame([], $this->seeks);
    }

    public function testItLoadsSeriesSpecsOncePerFormAndCommitsEachBatchAsOneTransaction(): void
    {
        $registry = $this->registry = $this->createMock(SeriesRegistry::class);
        $registry->expects($this->once())->method('load')->with(7)->willReturn(['colour' => ['kind' => 1]]);

        $this->anonymise([$this->row(1, $this->payload()), $this->row(2, $this->payload())]);

        $this->assertCount(2, $this->replaced);
        $this->assertSame(['colour' => ['kind' => 1]], $this->replaced[1][3]);
        $this->assertSame(['beginTransaction', 'commit'], $this->transactions);
    }

    public function testItMigratesSchemasReadByTheAnonymiser(): void
    {
        $migrator = $this->createMock(SchemaMigrator::class);
        $migrator->expects($this->once())->method('upgrade')->willReturn(['pages' => [['items' => [
            ['kind' => 'field', 'name' => 'note', 'type' => 'text', 'personal' => true],
            ['kind' => 'field', 'name' => 'colour', 'type' => 'text', 'personal' => false],
        ]]]]);
        $this->migrator = $migrator;

        $this->anonymise([$this->row(1, $this->payload())]);

        [$data] = $this->submissionUpdate();
        $values = array_column($this->json->unserialize($data['payload_json']), 'value', 'name');
        $this->assertSame('', $values['note']);
        $this->assertSame('red', $values['colour']);
    }

    public function testItBlanksEveryAnswerAndLogsWhenTheSchemaIsNewerThanSupported(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->migrator = $this->createStub(SchemaMigrator::class);
        $this->migrator->method('upgrade')->willThrowException(new UnsupportedSchemaVersionException(9, 'newer'));
        $logger->expects($this->once())->method('warning')->with($this->stringContains('7'));

        $this->anonymise([$this->row(1, $this->payload())]);

        [$data] = $this->submissionUpdate();
        $values = array_column($this->json->unserialize($data['payload_json']), 'value', 'name');
        $this->assertSame(['', '', '', ''], array_values($values));
    }
}
