<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Claim;

use Furan\FormBuilder\Model\Claim\ClaimService;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Website;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ClaimServiceTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private Mysql&Stub $connection;
    private Share&Stub $share;
    private Respondents&Stub $respondents;
    /** @var array<int, array{string, array}> */
    private array $calls = [];

    private function service(mixed $found): ClaimService
    {
        $this->calls = [];
        $this->connection = $this->double('connection', Mysql::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'limit', 'order'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->calls[] = [$method, $args];
                return $select;
            });
        }
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn($found);

        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn('k0 k1');
        $this->share = $this->createStub(Share::class);
        $website = $this->createStub(Website::class);
        $website->method('getStoreIds')->willReturn([3 => 3, 4 => 4]);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        $this->respondents = $this->double('respondents', Respondents::class);

        return new ClaimService(
            $resource,
            new EmailHasher($config),
            $this->share,
            $storeManager,
            $this->respondents
        );
    }

    private function where(): array
    {
        return array_values(array_filter($this->calls, fn(array $c): bool => $c[0] === 'where'));
    }

    public function testItFindsUnlinkedGuestSubmissionsUnderAnyCryptKeyVersionOfTheEmailFingerprint(): void
    {
        $service = $this->service('17');
        $this->share->method('isWebsiteScope')->willReturn(false);

        $this->assertTrue($service->hasUnlinked(' Jo@Example.com ', 1));
        $where = $this->where();
        $this->assertSame('email_hash IN (?)', $where[0][1][0]);
        $this->assertCount(2, $where[0][1][1], 'one fingerprint per key version');
        $this->assertSame('customer_id IS NULL', $where[1][1][0]);
        $this->assertCount(2, $where);
        $this->assertContains(['limit', [1]], $this->calls);
    }

    public function testItIsFalseWhenNothingIsUnlinkedOrTheEmailIsEmpty(): void
    {
        $this->assertFalse($this->service(false)->hasUnlinked('jo@example.com', 1));
        $this->assertFalse($this->service('1')->hasUnlinked('  ', 1));
    }

    public function testItRestrictsToTheCustomersWebsiteStoresWhenAccountsAreWebsiteScoped(): void
    {
        $service = $this->service('1');
        $this->share->method('isWebsiteScope')->willReturn(true);

        $service->hasUnlinked('jo@example.com', 2);
        $this->assertSame(['store_id IN (?)', [3, 4]], $this->where()[2][1]);
    }

    public function testItLinksGuestSubmissionsWithinTheCustomersWebsiteScopeOnly(): void
    {
        $this->asserted = ['connection'];
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(true);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls([5, 9], [], []);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->expects($this->once())->method('update')->willReturn(2);

        $this->assertSame(2, $service->claim(7, 'jo@example.com', 2));
        $this->assertContains(['where', ['store_id IN (?)', [3, 4]]], $this->calls);
    }

    public function testItLinksOnlyRowsThatAreStillUnlinked(): void
    {
        $this->asserted = ['connection'];
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(false);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls([5, 9], [], []);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->expects($this->once())->method('update')->with(
            'furan_formbuilder_submission',
            $this->callback(static fn (array $set): bool => $set['customer_id'] === 7
                && (string)$set['thread_token_version'] === 'thread_token_version + 1'),
            ['submission_id IN (?)' => [5, 9], 'customer_id IS NULL']
        )->willReturn(1);

        $this->assertSame(1, $service->claim(7, 'jo@example.com', 2));
    }

    public function testItLinksGuestSubmissionsFingerprintedUnderAnOlderCryptKeyVersion(): void
    {
        $this->asserted = ['connection'];
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(false);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls([5], [6], []);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->expects($this->exactly(2))->method('update')->willReturn(1);

        $this->assertSame(2, $service->claim(7, 'jo@example.com', 2));
        $hashes = array_values(array_filter(
            $this->calls,
            fn(array $c): bool => $c[0] === 'where' && $c[1][0] === 'email_hash = ?'
        ));
        $this->assertCount(2, $hashes, 'one index seek per key version');
        $this->assertNotSame($hashes[0][1][1], $hashes[1][1][1]);
    }

    public function testItMovesGuestRespondentKeysOfLinkedRowsToTheCustomerKey(): void
    {
        $this->asserted = ['respondents'];
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(false);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls([5], []);
        $this->connection->method('fetchAll')->willReturn([['form_id' => '3', 'respondent_key' => 'eGUEST']]);
        $this->connection->method('update')->willReturn(1);
        $this->respondents->method('customerKey')->with(7)->willReturn('cKEY');
        $this->respondents->expects($this->once())->method('rekey')->with(3, 'eGUEST', 'cKEY');

        $service->claim(7, 'jo@example.com', 2);
    }

    public function testItAdvancesByKeysetNeverByOffset(): void
    {
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(false);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls(range(1, 500), [], []);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('update')->willReturn(500);

        $service->claim(7, 'jo@example.com', 2);
        $this->assertContains(['where', ['submission_id > ?', 500]], $this->calls);
        $this->assertContains(['limit', [500]], $this->calls);
        $this->assertNotContains('offset', array_column($this->calls, 0));
    }

    public function testItNeverTurnsAClaimedGuestAnswerIntoTheCustomersSurveyResponse(): void
    {
        $this->asserted = ['respondents'];
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(false);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls([5, 9], []);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('update')->willReturn(2);
        $this->respondents->expects($this->never())->method('add');

        $this->assertSame(2, $service->claim(7, 'jo@example.com', 2));
    }

    public function testItLinksAndRekeysEachBatchInOneTransaction(): void
    {
        $this->asserted = ['connection'];
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(false);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls([5], [], []);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('update')->willReturn(1);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $service->claim(7, 'jo@example.com', 2);
    }

    public function testItRollsTheLinkBackWhenRekeyingRespondentsFails(): void
    {
        $this->asserted = ['connection'];
        $service = $this->service(false);
        $this->share->method('isWebsiteScope')->willReturn(false);
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls([5], []);
        $this->connection->method('fetchAll')->willReturn([['form_id' => '3', 'respondent_key' => 'eGUEST']]);
        $this->connection->method('update')->willReturn(1);
        $this->respondents->method('rekey')->willThrowException(new \RuntimeException('boom'));
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->never())->method('commit');
        $this->connection->expects($this->once())->method('rollBack');

        $this->expectExceptionMessage('boom');
        $service->claim(7, 'jo@example.com', 2);
    }
}
