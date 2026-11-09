<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Controller\Account\Submission;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private SubmissionReader&Stub $reader;
    private Forward&Stub $forward;
    private PageFactory&Stub $pageFactory;

    private function controller(mixed $customerId, mixed $id): Submission
    {
        $this->reader = $this->double('reader', SubmissionReader::class);
        $this->forward = $this->double('forward', Forward::class);
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($this->forward);
        $this->pageFactory = $this->double('pageFactory', PageFactory::class);
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn($customerId);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('id')->willReturn($id);
        return new Submission($this->pageFactory, $forwardFactory, $session, $request, $this->reader);
    }

    public function testItReturns404ForAnotherCustomersSubmission(): void
    {
        $this->asserted = ['reader', 'forward', 'pageFactory'];
        $controller = $this->controller('5', '9');
        $this->reader->expects($this->once())->method('find')->with(5, 9)->willReturn(null);
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $this->pageFactory->expects($this->never())->method('create');

        $this->assertSame($this->forward, $controller->execute());
    }

    public function testItReturns404WhenTheSessionHasNoCustomerId(): void
    {
        $this->asserted = ['reader', 'forward'];
        $controller = $this->controller(null, '9');
        $this->reader->expects($this->never())->method('find');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $controller->execute());
    }

    public function testItRendersTheOwnSubmission(): void
    {
        $this->asserted = ['reader'];
        $controller = $this->controller('5', '9');
        $this->reader->method('find')->with(5, 9)->willReturn(['submission_id' => '9']);
        $page = $this->createStub(Page::class);
        $this->pageFactory->method('create')->willReturn($page);

        $this->assertSame($page, $controller->execute());
    }
}
