<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Submission\View;
use Magento\Backend\Model\View\Result\Page;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;

class ViewTest extends SubmissionControllerTestCase
{
    public function testItMarksAnUnreadSubmissionReadWhenViewed(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('7');
        $submission = $this->createMock(SubmissionInterface::class);
        $submission->method('getId')->willReturn(7);
        $submission->method('isRead')->willReturn(false);
        $submission->expects($this->never())->method('setIsRead');
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn($submission);
        $repository->expects($this->never())->method('save');
        $resource = $this->createMock(SubmissionResource::class);
        $resource->expects($this->once())->method('markRead')->with(7)->willReturn(true);

        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend')->with($this->callback(
            static fn($value): bool => (string)$value === 'Submission #7'
        ));
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $this->assertSame($page, (new View($this->context, $pageFactory, $repository, $resource))->execute());
    }

    public function testItDoesNotSaveAnAlreadyReadSubmission(): void
    {
        $this->request->method('getParam')->willReturn('7');
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('isRead')->willReturn(true);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $repository->method('getById')->willReturn($submission);
        $repository->expects($this->never())->method('save');
        $resource = $this->createMock(SubmissionResource::class);
        $resource->expects($this->never())->method('markRead');
        $page = $this->createStub(Page::class);
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($this->createStub(Title::class));
        $page->method('getConfig')->willReturn($config);
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        (new View($this->context, $pageFactory, $repository, $resource))->execute();
    }

    public function testItRedirectsWithAnErrorForAnUnknownSubmission(): void
    {
        $this->request->method('getParam')->willReturn('99');
        $repository = $this->createStub(SubmissionRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('missing')));
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $result = (new View($this->context, $this->createStub(PageFactory::class), $repository, $this->createStub(SubmissionResource::class)))->execute();

        $this->assertSame($this->redirect, $result);
    }
}
