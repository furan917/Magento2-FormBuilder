<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Form\MassDelete;
use Furan\FormBuilder\Controller\Adminhtml\Form\MassStatus;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\ResourceModel\Form\Collection;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Furan\FormBuilder\Model\Form\Purger;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

class MassActionsTest extends ControllerTestCase
{
    private Filter&MockObject $filter;
    private CollectionFactory&Stub $collectionFactory;
    private Collection&Stub $filtered;
    private FormRepositoryInterface&Stub $repository;
    /** @var Form[]&MockObject[] */
    private array $forms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->forms = [$this->createStub(Form::class), $this->createStub(Form::class)];
        $modelCollection = $this->createStub(Collection::class);
        $filtered = $this->filtered = $this->createStub(Collection::class);
        $filtered->method('getIterator')->willReturnCallback(fn (): \ArrayIterator => new \ArrayIterator($this->forms));
        $filtered->method('getSize')->willReturn(2);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($modelCollection);
        $this->filter = $this->createMock(Filter::class);
        $this->filter->expects($this->once())->method('getCollection')->with($modelCollection)
            ->willReturn($filtered);
        $this->repository = $this->createStub(FormRepositoryInterface::class);
        $this->expectingRedirect()->method('setPath')->with('*/*/')->willReturnSelf();
    }

    public function testItMassDeletesSelectedForms(): void
    {
        $repository = $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->allow(true);
        $repository->expects($this->never())->method('delete');
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $delete = new MassDelete(
            $this->context,
            $this->filter,
            $this->collectionFactory,
            $this->submissions([])
        );
        $this->assertInstanceOf(HttpPostActionInterface::class, $delete);
        $this->assertSame($this->redirect, $delete->execute());
        $this->assertSame($this->forms, $this->deleted);
    }

    public function testItSkipsProtectedFormsInMassDeleteAndReportsThem(): void
    {
        $repository = $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->allow(false);
        $this->forms[0]->method('getId')->willReturn(1);
        $this->forms[0]->method('getTitle')->willReturn('Contact');
        $this->forms[1]->method('getId')->willReturn(2);
        $repository->expects($this->never())->method('delete');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')->with(
            $this->callback(static fn ($m) => (string)$m === 'The form "Contact" has submissions. '
                . 'You need permission to delete submissions to delete it.')
        );
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')->with(
            $this->callback(static fn ($m) => (string)$m === 'A total of 1 form(s) have been deleted.')
        );
        $delete = new MassDelete(
            $this->context,
            $this->filter,
            $this->collectionFactory,
            $this->submissions([1 => 4, 2 => 0])
        );
        $this->assertSame($this->redirect, $delete->execute());
        $this->assertSame([$this->forms[1]], $this->deleted);
    }

    public function testItMassTogglesOnlyTheStatusColumnAndPurgesTheSelectedForms(): void
    {
        $this->filtered->method('getAllIds')->willReturn(['4', '9']);
        $resource = $this->createMock(FormResource::class);
        $resource->expects($this->once())->method('setActive')->with([4, 9], false)->willReturn(1);
        $repository = $this->repository = $this->createMock(FormRepositoryInterface::class);
        $repository->expects($this->never())->method('save');
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('dispatch')->with(
            'clean_cache_by_tags',
            $this->callback(static fn (array $data): bool => $data['object']->getIdentities() === ['furan_form_4', 'furan_form_9'])
        );
        $this->context->method('getEventManager')->willReturn($events);
        $contexts = $this->createStub(CacheContextFactory::class);
        $contexts->method('create')->willReturnCallback(static fn (): CacheContext => new CacheContext());
        $this->expectingRequest()->method('getParam')->with('status')->willReturn('0');
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')->with(
            $this->callback(static fn ($m) => (string)$m === 'A total of 1 form(s) have been updated.')
        );

        $status = new MassStatus($this->context, $this->filter, $this->collectionFactory, $resource, $contexts);
        $this->assertInstanceOf(HttpPostActionInterface::class, $status);
        $this->assertSame($this->redirect, $status->execute());
    }

    public function testItSkipsTheUpdateAndPurgeWhenNothingIsSelected(): void
    {
        $this->filtered->method('getAllIds')->willReturn([]);
        $resource = $this->createMock(FormResource::class);
        $resource->expects($this->never())->method('setActive');
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('dispatch');
        $this->context->method('getEventManager')->willReturn($events);
        $this->expectingRequest()->method('getParam')->with('status')->willReturn('1');
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')->with(
            $this->callback(static fn ($m) => (string)$m === 'A total of 0 form(s) have been updated.')
        );

        (new MassStatus($this->context, $this->filter, $this->collectionFactory, $resource, $this->createStub(CacheContextFactory::class)))
            ->execute();
    }

    private function allow(bool $allowed): void
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(
            static fn ($r) => $r !== 'Furan_FormBuilder::submissions_delete' || $allowed
        );
        $this->context->method('getAuthorization')->willReturn($auth);
    }

    /** @param array<int, int> $sizes submission count per form id */
    private array $deleted = [];

    private function submissions(array $sizes): Purger
    {
        $purger = $this->createStub(Purger::class);
        $purger->method('hasSubmissions')->willReturnCallback(static fn (int $id): bool => ($sizes[$id] ?? 0) > 0);
        $purger->method('deleteOrSchedule')->willReturnCallback(function ($form): bool {
            $this->deleted[] = $form;
            return true;
        });
        return $purger;
    }
}
