<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\ResourceModel;

use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission;
use Furan\FormBuilder\Model\Submission\Reference;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class SubmissionTest extends TestCase
{
    /** @var list<array{string, array<mixed>}> */
    private array $selectCalls = [];

    private function resource(AdapterInterface $connection): SubmissionResource
    {
        $resources = $this->createStub(ResourceConnection::class);
        $resources->method('getConnection')->willReturn($connection);
        $resources->method('getTableName')->willReturnArgument(0);
        $context = $this->createStub(DbContext::class);
        $context->method('getResources')->willReturn($resources);

        return new SubmissionResource($context);
    }

    private function recordingSelect(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'limit', 'distinct'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select): Select {
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->selectCalls[] = [$method, $args];
                return $select;
            });
        }
        return $select;
    }

    public function testItMarksASubmissionReadWithAColumnOnlyUpdateOfUnreadRows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('update')
            ->with('furan_formbuilder_submission', ['is_read' => 1], ['submission_id = ?' => 7, 'is_read = ?' => 0])
            ->willReturn(1);

        $this->assertTrue($this->resource($connection)->markRead(7));
    }

    public function testItMarksASubmissionUnreadWithAColumnOnlyUpdateOfReadRows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('update')
            ->with('furan_formbuilder_submission', ['is_read' => 0], ['submission_id = ?' => 7, 'is_read = ?' => 1])
            ->willReturn(1);

        $this->assertTrue($this->resource($connection)->markUnread(7));
    }

    public function testItReportsNoChangeForAnAlreadyReadSubmission(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('update')->willReturn(0);

        $this->assertFalse($this->resource($connection)->markRead(7));
    }

    public function testItCountsAtMostTheGivenNumberOfRows(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->recordingSelect());
        $connection->method('fetchOne')->willReturn('10001');

        $this->assertSame(10001, $this->resource($connection)->countUpTo(10001));
        $this->assertContains(['limit', [10001]], $this->selectCalls);
        $this->assertContains(['from', ['furan_formbuilder_submission', ['submission_id']]], $this->selectCalls);
    }

    public function testItListsTheDistinctFormsACustomerSubmittedWithABoundCustomerId(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->recordingSelect());
        $connection->method('fetchCol')->willReturn(['3', '4']);

        $this->assertSame([3, 4], $this->resource($connection)->customerFormIds(5));
        $this->assertContains(['distinct', [true]], $this->selectCalls);
        $this->assertContains(['where', ['customer_id = ?', 5]], $this->selectCalls);
    }

    public function testItCountsACustomersSubmissionsOfTheGivenFormsWithBoundValues(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->recordingSelect());
        $connection->method('fetchOne')->willReturn('2');

        $this->assertSame(2, $this->resource($connection)->countForCustomer(5, [3, 4]));
        $this->assertContains(['where', ['customer_id = ?', 5]], $this->selectCalls);
        $this->assertContains(['where', ['form_id IN (?)', [3, 4]]], $this->selectCalls);
    }

    public function testItCountsNothingForNoForms(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('fetchOne');

        $this->assertSame(0, $this->resource($connection)->countForCustomer(5, []));
    }

    private function model(bool $new): Submission
    {
        $model = new Submission(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $this->createStub(SubmissionResource::class)
        );
        $model->isObjectNew($new);

        return $model;
    }

    private function beforeSave(Submission $model): void
    {
        $resource = (new \ReflectionClass(SubmissionResource::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(SubmissionResource::class, '_beforeSave'))->invoke($resource, $model);
    }

    public function testItAssignsAReferenceWhenASubmissionIsCreated(): void
    {
        $model = $this->model(true);

        $this->beforeSave($model);

        $this->assertSame(Reference::BYTES, strlen((string)$model->getData('reference')));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $model->getReference());
    }

    public function testItKeepsAReferenceThatWasAlreadySet(): void
    {
        $model = $this->model(true);
        $model->setReference('018f3a5b-c0de-7abc-b123-456789abcdef');

        $this->beforeSave($model);

        $this->assertSame('018f3a5b-c0de-7abc-b123-456789abcdef', $model->getReference());
    }

    public function testItNeverTouchesTheReferenceOfAnExistingSubmission(): void
    {
        $model = $this->model(false);

        $this->beforeSave($model);

        $this->assertNull($model->getData('reference'));
    }

    public function testItNeverExposesRawReferenceBytes(): void
    {
        $model = $this->model(false);
        $model->setData('reference', "\xff\xfe\x00");

        $this->assertSame('', $model->getReference());
        $this->assertSame('018f3a5b-c0de-7abc-b123-456789abcdef', $this->withBytes('018f3a5b-c0de-7abc-b123-456789abcdef')->getReference());
    }

    private function withBytes(string $uuid): Submission
    {
        $model = $this->model(false);
        $model->setData('reference', Reference::parse($uuid));

        return $model;
    }
}
