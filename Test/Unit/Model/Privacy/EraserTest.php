<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Privacy;

use Furan\FormBuilder\Model\Privacy\AccountLookup;
use Furan\FormBuilder\Model\Privacy\Eraser;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Survey\Respondents;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EraserTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private BatchDeleter&Stub $deleter;
    private Respondents&Stub $respondents;
    private EmailHasher&Stub $hasher;
    private AccountLookup&Stub $accounts;
    private SubmissionDeliveryCleaner&Stub $cleaner;
    private Eraser $eraser;
    /** @var array<int, array{string, array}> */
    private array $selects = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?AccountLookup $accounts = null, ?AdapterInterface $connection = null, ?EmailHasher $hasher = null, ?Respondents $respondents = null, ?SubmissionDeliveryCleaner $cleaner = null): void
    {
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->deleter = $this->createStub(BatchDeleter::class);
        $this->respondents = $respondents ?? $this->createStub(Respondents::class);
        $this->hasher = $hasher ?? $this->createStub(EmailHasher::class);
        $this->accounts = $accounts ?? $this->createStub(AccountLookup::class);
        $this->cleaner = $cleaner ?? $this->createStub(SubmissionDeliveryCleaner::class);
        $this->eraser = new Eraser($resource, $this->deleter, $this->respondents, $this->hasher, $this->accounts, $this->cleaner);
    }

    /** @param array<int, int[]> $pages ids returned by successive seeks */
    private function seekPages(array $pages): void
    {
        $this->connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind) use (&$pages) {
            $this->selects[] = [$sql, $bind];
            return array_shift($pages) ?? [];
        });
    }

    public function testItErasesACustomersSubmissionsThroughTheJournaledDeletePath(): void
    {
        $first = range(1, 500);
        $this->seekPages([$first, [501, 502]]);
        $deleted = [];
        $this->deleter->method('deleteBatch')->willReturnCallback(function (array $ids) use (&$deleted): int {
            $deleted[] = $ids;
            return count($ids);
        });

        $this->assertSame(502, $this->eraser->eraseCustomer(7));

        $this->assertSame([$first, [501, 502]], $deleted);
        $this->assertSame([7, 0], $this->selects[0][1]);
        $this->assertSame([7, 500], $this->selects[1][1]);
        $this->assertStringContainsString('customer_id = ? AND submission_id > ?', $this->selects[0][0]);
        $this->assertStringContainsString('ORDER BY submission_id LIMIT 500', $this->selects[0][0]);
        $this->assertStringNotContainsString('OFFSET', $this->selects[0][0]);
    }

    public function testItExcludesKeptFormsInSql(): void
    {
        $this->seekPages([[]]);
        $this->eraser->eraseCustomer(7, [3, 4]);

        $this->assertStringContainsString('form_id NOT IN (3,4)', $this->selects[0][0]);
        $this->assertSame([7, 0], $this->selects[0][1]);
    }

    public function testItDeletesTheCustomersServerDraftsWhenErasing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->seekPages([[]]);
        $connection->expects($this->once())->method('delete')
            ->with('furan_formbuilder_draft', ['customer_id = ?' => 7]);

        $this->eraser->eraseCustomer(7);
    }

    public function testItRemovesTheCustomersDetachedSurveyRespondentsWhenErasing(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->seekPages([[]]);
        $respondents->method('customerKey')->with(7)->willReturn('c-key');
        $respondents->expects($this->once())->method('removeByKeys')->with(['c-key']);

        $this->eraser->eraseCustomer(7);
    }

    public function testItDetachesRespondentsInsideTheBatchTransactionWhenKeepingThem(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(connection: $connection, respondents: $respondents);
        $this->seekPages([[5, 6]]);
        $order = [];
        $connection->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$order) { $order[] = 'begin'; return $this->connection; });
        $respondents->expects($this->once())->method('detach')->with([5, 6])
            ->willReturnCallback(function () use (&$order): int { $order[] = 'detach'; return 2; });
        $this->deleter->method('deleteBatch')->willReturnCallback(function () use (&$order): int {
            $order[] = 'delete';
            return 2;
        });
        $connection->expects($this->once())->method('commit')
            ->willReturnCallback(function () use (&$order) { $order[] = 'commit'; return $this->connection; });
        $respondents->expects($this->never())->method('removeByKeys');

        $this->eraser->eraseCustomer(7, [], true);

        $this->assertSame(['begin', 'detach', 'delete', 'commit'], $order);
    }

    public function testItClearsDeliveriesBeforeTheErasureTransactionOpens(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $cleaner = $this->createMock(SubmissionDeliveryCleaner::class);
        $this->fixture(connection: $connection, cleaner: $cleaner);
        $this->seekPages([[5, 6]]);
        $order = [];
        $cleaner->expects($this->once())->method('deleteFor')->with([5, 6])
            ->willReturnCallback(function () use (&$order): void { $order[] = 'clean'; });
        $connection->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$order) { $order[] = 'begin'; return $this->connection; });

        $this->eraser->eraseCustomer(7, [], true);

        $this->assertSame(['clean', 'begin'], $order);
    }

    public function testItRollsBackWhenAKeptRespondentBatchFails(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->seekPages([[5]]);
        $this->deleter->method('deleteBatch')->willThrowException(new \RuntimeException('boom'));
        $connection->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->eraser->eraseCustomer(7, [], true);
    }

    public function testItErasesGuestSubmissionsByEveryEmailFingerprintKeyVersion(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $hasher = $this->createMock(EmailHasher::class);
        $this->fixture(respondents: $respondents, hasher: $hasher);
        $hasher->method('candidates')->with('A@b.c')->willReturn([0 => 'h0', 1 => 'h1']);
        $this->seekPages([[1, 2], [9]]);
        $this->deleter->method('deleteBatch')->willReturnCallback(static fn (array $ids): int => count($ids));
        $respondents->method('emailKeys')->with('A@b.c')->willReturn(['eh0', 'eh1']);
        $respondents->expects($this->once())->method('removeByKeys')->with(['eh0', 'eh1']);

        $this->assertSame(3, $this->eraser->eraseEmail('A@b.c'));

        $this->assertSame(['h0', 0], $this->selects[0][1]);
        $this->assertSame(['h1', 0], $this->selects[1][1]);
        $this->assertStringContainsString('email_hash = ? AND submission_id > ?', $this->selects[0][0]);
    }

    public function testItAlsoErasesTheAccountsRegisteredWithTheEmailIncludingTheirDrafts(): void
    {
        $accounts = $this->createMock(AccountLookup::class);
        $this->fixture(accounts: $accounts);
        $accounts->method('customerIds')->with('a@b.c')->willReturn([7, 12]);
        $this->hasher->method('candidates')->willReturn([0 => 'h0']);
        $this->seekPages([[1], [2], [3]]);
        $this->deleter->method('deleteBatch')->willReturnCallback(static fn (array $ids): int => count($ids));
        $drafts = [];
        $this->connection->method('delete')->willReturnCallback(function (string $table, array $where) use (&$drafts): int {
            $drafts[] = [$table, $where];
            return 1;
        });
        $this->respondents->method('customerKey')->willReturnCallback(static fn (int $id): string => 'c' . $id);
        $this->respondents->method('emailKeys')->willReturn(['eh0']);
        $removed = [];
        $this->respondents->method('removeByKeys')->willReturnCallback(function (array $keys) use (&$removed): int {
            $removed[] = $keys;
            return 1;
        });

        $this->assertSame(3, $this->eraser->eraseEmail('a@b.c'));

        $this->assertSame([7, 0], $this->selects[0][1]);
        $this->assertSame([12, 0], $this->selects[1][1]);
        $this->assertSame(['h0', 0], $this->selects[2][1]);
        $this->assertSame(
            [['furan_formbuilder_draft', ['customer_id = ?' => 7]], ['furan_formbuilder_draft', ['customer_id = ?' => 12]]],
            $drafts
        );
        $this->assertSame([['c7'], ['c12'], ['eh0']], $removed);
    }

    public function testItPreviewsTheMatchedSubmissionsPerFormBeforeErasingByEmail(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->accounts->method('customerIds')->willReturn([7]);
        $this->hasher->method('candidates')->willReturn([0 => 'h0', 1 => 'h1']);
        $connection->expects($this->once())->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'COUNT(*)')
                    && str_contains($sql, 'SELECT submission_id, form_id FROM furan_formbuilder_submission WHERE customer_id IN (7)'
                        . ' UNION SELECT submission_id, form_id FROM furan_formbuilder_submission WHERE email_hash IN (?, ?)')
                    && !str_contains($sql, ' OR ')
                    && !str_contains($sql, 'UNION ALL')
                    && str_contains($sql, 'GROUP BY u.form_id')
                    && !str_contains($sql, 'payload_json')),
                ['h0', 'h1']
            )
            ->willReturn([
                ['form_id' => '3', 'title' => 'Contact', 'n' => '4'],
                ['form_id' => '9', 'title' => null, 'n' => '1'],
            ]);

        $this->assertSame(
            ['total' => 5, 'accounts' => 1, 'forms' => [
                ['form_id' => 3, 'title' => 'Contact', 'count' => 4],
                ['form_id' => 9, 'title' => '', 'count' => 1],
            ]],
            $this->eraser->preview(0, 'a@b.c')
        );
    }

    public function testItPreviewsACustomerById(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $accounts = $this->createMock(AccountLookup::class);
        $this->fixture(connection: $connection, accounts: $accounts);
        $accounts->expects($this->never())->method('customerIds');
        $connection->expects($this->once())->method('fetchAll')
            ->with($this->callback(static fn (string $sql): bool => str_contains($sql, '(SELECT submission_id, form_id FROM furan_formbuilder_submission WHERE customer_id IN (7)) u')
                && !str_contains($sql, 'UNION')), [])
            ->willReturn([]);

        $this->assertSame(['total' => 0, 'accounts' => 1, 'forms' => []], $this->eraser->preview(7, ''));
    }
}
