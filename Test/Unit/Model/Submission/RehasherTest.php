<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\EmailResolver;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Submission\Rehasher;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RehasherTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private EmailHasher&Stub $hasher;
    private EmailResolver&Stub $emailResolver;
    private Respondents&Stub $respondents;
    private Rehasher $rehasher;
    /** @var array<int, array> */
    private array $fetches = [];
    /** @var array<int, array> */
    private array $submissionBatches = [];
    /** @var array<int, array> */
    private array $updates = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?EmailResolver $emailResolver = null, ?Respondents $respondents = null): void
    {
        $this->connection = $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->hasher = $this->createStub(EmailHasher::class);
        $this->hasher->method('candidates')->willReturnCallback(
            static fn(string $email): array => [0 => 'A:' . $email, 1 => 'B:' . $email]
        );
        $this->hasher->method('hash')->willReturnCallback(
            static fn(string $email): array => ['hash' => 'B:' . $email, 'version' => 1]
        );
        $this->emailResolver = $emailResolver ?? $this->createStub(EmailResolver::class);
        $this->respondents = $respondents ?? $this->createStub(Respondents::class);
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind = []) {
            if (str_contains($sql, 'furan_formbuilder_form')) {
                return [['form_id' => 5, 'settings_json' => '{"email_field":"mail"}']];
            }
            $this->fetches[] = ['sql' => $sql, 'bind' => $bind];
            return array_shift($this->submissionBatches) ?? [];
        });
        $this->connection->method('update')->willReturnCallback(function ($table, $bind, $where) {
            $this->updates[] = [$table, $bind, $where];
            return 1;
        });
        $this->rehasher = new Rehasher($resource, $this->hasher, $this->emailResolver, $this->respondents, new Json(), $this->createStub(\Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex::class));
    }

    private function row(int $id, int $version = 0, ?int $customer = null, string $email = 'a@b.c'): array
    {
        return [
            'submission_id' => $id, 'form_id' => 5, 'customer_id' => $customer, 'payload_json' => '[]',
            'email_hash' => ($version === 0 ? 'A:' : 'B:') . $email, 'email_hash_version' => $version,
        ];
    }

    private function emails(array $map): void
    {
        $this->emailResolver->method('resolve')->willReturn($map);
    }

    public function testItReFingerprintsRowsHashedWithAnOlderKeyVersionUsingTheNewestKey(): void
    {
        $this->submissionBatches = [[$this->row(1)]];
        $this->emails([1 => 'a@b.c']);

        $stats = $this->rehasher->run(10, null);

        $this->assertSame(
            ['furan_formbuilder_submission', ['email_hash' => 'B:a@b.c', 'email_hash_version' => 1],
                ['submission_id = ?' => 1, 'email_hash = ?' => 'A:a@b.c']],
            $this->updates[0]
        );
        $this->assertSame(1, $stats['rekeyed']);
        $this->assertSame(1, $stats['scanned']);
    }

    public function testItCommitsEachBatchInOneTransactionRatherThanPerRow(): void
    {
        $log = [];
        $this->connection->method('beginTransaction')->willReturnCallback(function () use (&$log) {
            $log[] = 'begin';
            return $this->connection;
        });
        $this->connection->method('commit')->willReturnCallback(function () use (&$log) {
            $log[] = 'commit';
            return $this->connection;
        });
        $this->submissionBatches = [[$this->row(1), $this->row(2), $this->row(3)]];
        $this->emails([1 => 'a@b.c', 2 => 'a@b.c', 3 => 'a@b.c']);

        $this->rehasher->run(500, null);

        $this->assertCount(3, $this->updates);
        $this->assertSame(['begin', 'commit'], $log);
    }

    public function testItResolvesTheEmailThroughTheExtractedEmailResolver(): void
    {
        $emailResolver = $this->createMock(EmailResolver::class);
        $this->fixture(emailResolver: $emailResolver);
        $rows = [$this->row(1), $this->row(2, 0, 9)];
        $this->submissionBatches = [$rows];
        $emailResolver->expects($this->once())->method('resolve')
            ->with($rows, [5 => ['email_field' => 'mail']])
            ->willReturn([]);

        $this->rehasher->run(10, null);
    }

    public function testItWalksRowsInKeysetBatchesAndNeverRevisitsARow(): void
    {
        $this->submissionBatches = [[$this->row(1), $this->row(2)], [$this->row(7)]];
        $this->emails([1 => 'a@b.c', 2 => 'a@b.c', 7 => 'a@b.c']);

        $stats = $this->rehasher->run(2, 5);

        $this->assertCount(2, $this->fetches);
        $this->assertSame(0, $this->fetches[0]['bind'][':last']);
        $this->assertSame(2, $this->fetches[1]['bind'][':last']);
        $this->assertSame(5, $this->fetches[1]['bind'][':form']);
        $this->assertStringNotContainsString('OFFSET', $this->fetches[0]['sql']);
        $this->assertStringContainsString('ORDER BY submission_id LIMIT 2', $this->fetches[0]['sql']);
        $this->assertSame(3, $stats['scanned']);
    }

    public function testItLeavesRowsAlreadyOnTheNewestKeyVersionUntouched(): void
    {
        $this->submissionBatches = [[$this->row(1, 1)]];
        $this->emails([1 => 'a@b.c']);

        $stats = $this->rehasher->run(10, null);

        $this->assertSame([], $this->updates);
        $this->assertSame(0, $stats['rekeyed']);
        $this->assertSame(0, $stats['unresolved']);
        $this->assertSame(1, $stats['scanned']);
    }

    public function testItRewritesTheGuestRespondentKeyOfAReFingerprintedSubmission(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->submissionBatches = [[$this->row(1), $this->row(2, 0, 4)]];
        $this->emails([1 => 'a@b.c', 2 => 'a@b.c']);
        $respondents->expects($this->once())->method('rekey')->with(5, 'eA:a@b.c', 'eB:a@b.c');

        $stats = $this->rehasher->run(10, null);

        $this->assertSame(1, $stats['respondent_keys']);
        $this->assertSame(2, $stats['rekeyed']);
    }

    public function testItDropsTheOlderRespondentKeyWhenTheNewestKeyAlreadyExistsForTheForm(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        // The collision handling lives in Respondents::rekey (covered in RespondentsTest); the rehasher must delegate.
        $this->submissionBatches = [[$this->row(1)]];
        $this->emails([1 => 'a@b.c']);
        $respondents->expects($this->once())->method('rekey');

        $this->rehasher->run(10, null);
    }

    public function testItReportsRowsWhoseEmailCannotBeResolvedAndLeavesThemUnchanged(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->submissionBatches = [[$this->row(1), $this->row(2, 0, null, 'x@y.z'), $this->row(3)]];
        $this->emails([2 => 'changed@y.z', 3 => 'a@b.c']);
        $respondents->expects($this->once())->method('rekey');

        $stats = $this->rehasher->run(10, null);

        $this->assertSame(2, $stats['unresolved']);
        $this->assertSame(1, $stats['rekeyed']);
        $this->assertCount(1, $this->updates);
    }

    public function testItDoesNothingWhenOnlyOneCryptKeyIsConfigured(): void
    {
        $hasher = $this->createStub(EmailHasher::class);
        $hasher->method('candidates')->willReturn([0 => 'A']);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $rehasher = new Rehasher($resource, $hasher, $this->emailResolver, $this->respondents, new Json(), $this->createStub(\Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex::class));

        $this->assertSame(
            ['scanned' => 0, 'rekeyed' => 0, 'unresolved' => 0, 'respondent_keys' => 0],
            $rehasher->run(10, null)
        );
    }
}
