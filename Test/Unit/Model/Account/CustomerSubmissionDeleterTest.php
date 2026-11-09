<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Account;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Account\CustomerSubmissionDeleter;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Furan\FormBuilder\Model\Privacy\Eraser;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CustomerSubmissionDeleterTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private AdapterInterface&Stub $connection;
    private Respondents&Stub $respondents;
    private SubmissionRepositoryInterface&Stub $repository;
    private SubmissionResource&Stub $submissions;
    private Eraser&Stub $eraser;
    /** @var array<int, array<string, mixed>|null> form id => settings, null for a deleted form */
    private array $forms = [];

    private function deleter(): CustomerSubmissionDeleter
    {
        $this->connection = $this->double('connection', AdapterInterface::class);
        $this->respondents = $this->double('respondents', Respondents::class);
        $this->repository = $this->double('repository', SubmissionRepositoryInterface::class);
        $this->submissions = $this->double('submissions', SubmissionResource::class);
        $this->eraser = $this->double('eraser', Eraser::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $formRepository = $this->createStub(FormRepositoryInterface::class);
        $formRepository->method('getById')->willReturnCallback(function (int $id): FormInterface {
            $settings = $this->forms[$id] ?? null;
            if ($settings === null) {
                throw new NoSuchEntityException();
            }
            $form = $this->createStub(FormInterface::class);
            $form->method('getSettings')->willReturn($settings);
            return $form;
        });

        return new CustomerSubmissionDeleter(
            $resource,
            $this->respondents,
            $this->repository,
            $this->submissions,
            $formRepository,
            $this->eraser,
            new SubmissionPolicy()
        );
    }

    public function testItDetachesTheRespondentAndDeletesInOneTransaction(): void
    {
        $this->asserted = ['connection', 'respondents', 'repository'];
        $deleter = $this->deleter();
        $order = [];
        $this->connection->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'begin';
                return $this->connection;
            });
        $this->respondents->expects($this->once())->method('detach')->with([9])
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'detach';
                return 1;
            });
        $this->repository->expects($this->once())->method('deleteById')->with(9)
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'delete';
                return true;
            });
        $this->connection->expects($this->once())->method('commit')
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'commit';
                return $this->connection;
            });
        $this->connection->expects($this->never())->method('rollBack');

        $deleter->delete(9);

        $this->assertSame(['begin', 'detach', 'delete', 'commit'], $order);
    }

    public function testItRollsBackSoTheRespondentIsNeverLeftDetachedWhenTheDeleteFails(): void
    {
        $this->asserted = ['connection'];
        $deleter = $this->deleter();
        $this->repository->method('deleteById')->willThrowException(new CouldNotDeleteException(__('x')));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(CouldNotDeleteException::class);
        $deleter->delete(9);
    }

    public function testItDeletesAllButTheSubmissionsOfFormsThatDisallowDelete(): void
    {
        $this->asserted = ['eraser', 'submissions'];
        $this->forms = [3 => ['account_delete' => false], 4 => ['account_delete' => true]];
        $deleter = $this->deleter();
        $this->submissions->method('customerFormIds')->with(5)->willReturn([3, 4, 8]);
        $this->eraser->expects($this->once())->method('eraseCustomer')->with(5, [3], true)->willReturn(7);
        $this->submissions->expects($this->once())->method('countForCustomer')->with(5, [3])->willReturn(2);

        $this->assertSame(['deleted' => 7, 'kept' => 2], $deleter->deleteAll(5));
    }

    public function testItKeepsSurveyRespondentsAndDeletesEverythingWhenNoFormDisallowsDelete(): void
    {
        $this->asserted = ['eraser'];
        $deleter = $this->deleter();
        $this->submissions->method('customerFormIds')->willReturn([]);
        $this->submissions->method('countForCustomer')->willReturn(0);
        $this->eraser->expects($this->once())->method('eraseCustomer')->with(5, [], true)->willReturn(1);

        $this->assertSame(['deleted' => 1, 'kept' => 0], $deleter->deleteAll(5));
    }
}
