<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Survey;

use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Survey\AlreadyAnsweredException;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RespondentsTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private EmailHasher&Stub $hasher;
    private Respondents $respondents;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?AdapterInterface $connection = null, ?EmailHasher $hasher = null): void
    {
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->hasher = $hasher ?? $this->createStub(EmailHasher::class);
        $this->respondents = new Respondents($resource, $this->hasher);
    }

    public function testItBuildsACustomerKeyFromTheCustomerId(): void
    {
        $key = $this->respondents->customerKey(7);
        $this->assertSame('c' . hash('sha256', '7', true), $key);
        $this->assertSame(33, strlen($key));
    }

    public function testItBuildsEmailKeysFromTheHasher(): void
    {
        $hasher = $this->createMock(EmailHasher::class);
        $this->fixture(hasher: $hasher);
        $hasher->method('hash')->with('A@b.c')->willReturn(['hash' => 'H2', 'version' => 1]);
        $hasher->method('candidates')->with('A@b.c')->willReturn([0 => 'H1', 1 => 'H2']);
        $this->assertSame('eH2', $this->respondents->emailKey('A@b.c'));
        $this->assertSame(['eH1', 'eH2'], $this->respondents->emailKeys('A@b.c'));
    }

    public function testItHasNoEmailKeyForAnEmptyEmail(): void
    {
        $this->hasher->method('hash')->willReturn(null);
        $this->assertNull($this->respondents->emailKey(''));
    }

    public function testItFindsARespondentRowWithOneKeyedQuery(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('fetchRow')
            ->with($this->stringContains('respondent_key IN (?,?)'), [3, 'k1', 'k2'])
            ->willReturn(['submission_id' => '9']);
        $this->assertSame(['submission_id' => 9], $this->respondents->find(3, ['k1', 'k2']));
    }

    public function testItFindsADetachedRespondentRowWithANullSubmissionId(): void
    {
        $this->connection->method('fetchRow')->willReturn(['submission_id' => null]);
        $this->assertSame(['submission_id' => null], $this->respondents->find(3, ['k1']));
    }

    public function testItFindsNoRowAndSkipsTheQueryWithoutKeys(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('fetchRow')->willReturn(false);
        $this->assertNull($this->respondents->find(3, ['k1']));
        $this->assertNull($this->respondents->find(3, []));
    }

    public function testItInsertsTheRespondentRowWithARawAdapterInsert(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('insert')
            ->with('furan_formbuilder_respondent', ['form_id' => 7, 'respondent_key' => 'cK', 'submission_id' => 41]);
        $this->respondents->add(7, 'cK', 41);
    }

    public function testItReportsADuplicateKeyAsAlreadyAnswered(): void
    {
        $this->connection->method('insert')->willThrowException(new DuplicateException('1062'));
        $this->expectException(AlreadyAnsweredException::class);
        $this->respondents->add(7, 'cK', 41);
    }

    public function testItRecordsAnEmailKeyWithInsertIgnoreSoAnExistingRowNeverBlocks(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('query')
            ->with(
                'INSERT IGNORE INTO furan_formbuilder_respondent (form_id, respondent_key, submission_id) VALUES (?, ?, ?)',
                [7, 'eH', 41]
            );
        $connection->expects($this->never())->method('insert');

        $this->respondents->addIfAbsent(7, 'eH', 41);
    }

    public function testItLetsOtherInsertErrorsThrough(): void
    {
        $this->connection->method('insert')->willThrowException(new \RuntimeException('boom'));
        $this->expectException(\RuntimeException::class);
        $this->respondents->add(7, 'cK', 41);
    }

    public function testItRefusesAGuestWhoseEmailWasRecordedUnderAnOlderKeyVersion(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->hasher->method('candidates')->willReturn([0 => 'H1', 1 => 'H2']);
        $connection->expects($this->once())->method('fetchRow')
            ->with($this->anything(), [7, 'eH1', 'eH2'])->willReturn(['submission_id' => '3']);
        $this->expectException(AlreadyAnsweredException::class);
        $this->respondents->assertNotAnswered(7, 'a@b.c');
    }

    public function testItSkipsTheEmailPreCheckWithASingleKeyVersion(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->hasher->method('candidates')->willReturn([0 => 'H1']);
        $connection->expects($this->never())->method('fetchRow');
        $this->respondents->assertNotAnswered(7, 'a@b.c');
    }

    public function testItPassesThePreCheckWhenNoRowMatches(): void
    {
        $this->hasher->method('candidates')->willReturn([0 => 'H1', 1 => 'H2']);
        $this->connection->method('fetchRow')->willReturn(false);
        $this->respondents->assertNotAnswered(7, 'a@b.c');
        $this->addToAssertionCount(1);
    }

    public function testItRemovesTheRespondentWhenTheSubmissionIsDeletedViaTheDeclaredCascade(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/db_schema.xml');
        $table = $xml->xpath('//table[@name="furan_formbuilder_respondent"]')[0];
        $fk = $table->xpath('constraint[@xsi:type="foreign" and @column="submission_id"]');
        $fk = $fk ?: $table->xpath('constraint[@column="submission_id"]');
        $this->assertSame('CASCADE', (string)$fk[0]['onDelete']);
        $this->assertSame('furan_formbuilder_submission', (string)$fk[0]['referenceTable']);
    }

    public function testItKeepsTheSurveyRespondentWhenTheCustomerDeletesTheirAnswerSoTheFormCannotBeAnsweredAgain(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('update')
            ->with('furan_formbuilder_respondent', ['submission_id' => null], ['submission_id IN (?)' => [4, 9]])
            ->willReturn(2);
        $this->assertSame(2, $this->respondents->detach([4, 9]));
    }

    public function testItDetachesNothingForNoIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->never())->method('update');
        $this->assertSame(0, $this->respondents->detach([]));
    }

    public function testItRemovesRespondentRowsByKey(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('delete')
            ->with('furan_formbuilder_respondent', ['respondent_key IN (?)' => ['k1', 'k2']])
            ->willReturn(2);
        $this->assertSame(2, $this->respondents->removeByKeys(['k1', 'k2']));
    }

    public function testItRemovesNothingForNoKeys(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->never())->method('delete');
        $this->assertSame(0, $this->respondents->removeByKeys([]));
    }

    public function testItRekeysARespondentRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('update')
            ->with(
                'furan_formbuilder_respondent',
                ['respondent_key' => 'eNEW'],
                ['form_id = ?' => 3, 'respondent_key = ?' => 'eOLD']
            );
        $connection->expects($this->never())->method('delete');
        $this->respondents->rekey(3, 'eOLD', 'eNEW');
    }

    public function testItDropsTheOldRespondentRowWhenTheNewKeyAlreadyExists(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->connection->method('update')->willThrowException(new DuplicateException('dup'));
        $connection->expects($this->once())->method('delete')
            ->with('furan_formbuilder_respondent', ['form_id = ?' => 3, 'respondent_key = ?' => 'eOLD']);
        $this->respondents->rekey(3, 'eOLD', 'eNEW');
    }

    public function testItTellsWhetherAKeyHasAnsweredAnyOneResponseForm(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $key = $this->respondents->customerKey(7);
        $connection->expects($this->exactly(2))->method('fetchOne')
            ->with($this->stringContains('WHERE respondent_key = ? LIMIT 1'), [$key])
            ->willReturnOnConsecutiveCalls('1', false);

        $this->assertTrue($this->respondents->hasAnswered($key));
        $this->assertFalse($this->respondents->hasAnswered($key));
    }
}
