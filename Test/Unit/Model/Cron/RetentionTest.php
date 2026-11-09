<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Cron\Retention;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetentionTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $queries = [];

    private function locks(bool $free = true): LockManagerInterface
    {
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn($free);
        return $locks;
    }

    private function config(int $days): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getRetentionDays')->willReturn($days);
        return $config;
    }

    /** @param array<int, array<int, array{0: int, 1: string}>> $pages [id, created_at] rows per successive query */
    private function resource(array $pages): SubmissionResource
    {
        $this->queries = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use (&$pages): array {
            $this->queries[] = [$sql, $bind];
            $page = array_shift($pages) ?? [];
            return array_map(fn(array $r): array => ['submission_id' => (string)$r[0], 'created_at' => $r[1]], $page);
        });
        $resource = $this->createStub(SubmissionResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('furan_formbuilder_submission');
        return $resource;
    }

    public function testItDoesNothingWhenRetentionIsDisabled(): void
    {
        $deleter = $this->createMock(BatchDeleter::class);
        $deleter->expects($this->never())->method('deleteBatch');
        (new Retention($this->config(0), $this->resource([]), $deleter, $this->createStub(LoggerInterface::class), $this->locks()))->execute();
    }

    public function testItDeletesOldSubmissionsInBatchesPagingByLastId(): void
    {
        $batches = [];
        $deleter = $this->createStub(BatchDeleter::class);
        $deleter->method('deleteBatch')->willReturnCallback(function (array $ids) use (&$batches): int {
            $batches[] = $ids;
            return count($ids);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('Form Builder retention deleted 3 submission(s).');

        $before = time();
        (new Retention($this->config(30), $this->resource([
            [[1, '2020-01-01 00:00:00'], [2, '2020-01-02 00:00:00']],
            [[5, '2020-01-02 00:00:00']],
            [],
        ]), $deleter, $logger, $this->locks()))->execute();

        $this->assertSame([[1, 2], [5]], $batches);
        $this->assertEqualsWithDelta($before - 30 * 86400, strtotime($this->queries[0][1][0] . ' UTC'), 5);
        $this->assertCount(1, $this->queries[0][1], 'first batch has no cursor');
        $this->assertSame(['2020-01-02 00:00:00', '2020-01-02 00:00:00', 2], array_slice($this->queries[1][1], 1));
        $this->assertSame(['2020-01-02 00:00:00', '2020-01-02 00:00:00', 5], array_slice($this->queries[2][1], 1));
    }

    public function testItSeeksTheCreatedAtIndexInsteadOfScanningNewerRows(): void
    {
        $deleter = $this->createStub(BatchDeleter::class);
        $deleter->method('deleteBatch')->willReturn(1);
        (new Retention($this->config(30), $this->resource([[[1, '2020-01-01 00:00:00']], []]), $deleter,
            $this->createStub(LoggerInterface::class), $this->locks()))->execute();

        $sql = $this->queries[1][0];
        $this->assertStringContainsString('created_at < ?', $sql);
        $this->assertStringContainsString('(created_at > ? OR (created_at = ? AND submission_id > ?))', $sql);
        $this->assertStringContainsString('ORDER BY created_at, submission_id LIMIT 500', $sql);
        $this->assertStringNotContainsString('OFFSET', $sql);
    }

    public function testItStopsAndLogsWhenABatchFails(): void
    {
        $deleter = $this->createStub(BatchDeleter::class);
        $deleter->method('deleteBatch')->willThrowException(new CouldNotDeleteException(__('Could not delete the submission.')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $logger->expects($this->never())->method('info');

        (new Retention($this->config(30), $this->resource([[[1, '2020-01-01 00:00:00']], [[2, '2020-01-01 00:00:00']]]), $deleter, $logger, $this->locks()))->execute();
    }

    public function testItSkipsTheRunWhileAnotherNodeHoldsTheRetentionLock(): void
    {
        $deleter = $this->createMock(BatchDeleter::class);
        $deleter->expects($this->never())->method('deleteBatch');
        (new Retention($this->config(30), $this->resource([[[1, '2020-01-01 00:00:00']]]), $deleter,
            $this->createStub(LoggerInterface::class), $this->locks(false)))->execute();
        $this->assertSame([], $this->queries);
    }

    public function testItStopsAtTheTimeBudgetAndReleasesTheLockSoTheNextRunResumes(): void
    {
        $now = 0.0;
        $deleter = $this->createStub(BatchDeleter::class);
        $deleter->method('deleteBatch')->willReturnCallback(function (array $ids) use (&$now): int {
            $now += 200;
            return count($ids);
        });
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->with(Retention::LOCK_NAME, 0)->willReturn(true);
        $locks->expects($this->once())->method('unlock')->with(Retention::LOCK_NAME);
        $row = [[1, '2020-01-01 00:00:00']];
        (new Retention($this->config(30), $this->resource([$row, $row, $row, $row]), $deleter,
            $this->createStub(LoggerInterface::class), $locks, static function () use (&$now): float {
                return $now;
            }))->execute();

        $this->assertCount(2, $this->queries);
    }
}
