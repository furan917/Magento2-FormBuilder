<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

abstract class SubmissionControllerTestCase extends TestCase
{
    protected Context&Stub $context;
    protected RequestInterface&Stub $request;
    protected ManagerInterface&Stub $messageManager;
    protected Redirect&Stub $redirect;
    /** @var array<string, bool> */
    private array $handedOut = [];

    protected function setUp(): void
    {
        $this->handedOut = [];
        $this->request = $this->createStub(RequestInterface::class);
        $this->messageManager = $this->createStub(ManagerInterface::class);
        $this->redirect = $this->createStub(Redirect::class);
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_REDIRECT)->willReturnCallback(fn (): Redirect => $this->handOut('redirect', $this->redirect));
        $this->context = $this->createStub(Context::class);
        $this->context->method('getRequest')->willReturnCallback(fn (): RequestInterface => $this->handOut('request', $this->request));
        $this->context->method('getMessageManager')->willReturnCallback(fn (): ManagerInterface => $this->handOut('messageManager', $this->messageManager));
        $this->context->method('getResultFactory')->willReturn($resultFactory);
    }

    protected function expectingMessages(): ManagerInterface&MockObject
    {
        if (!$this->messageManager instanceof MockObject) {
            $this->assertNotHandedOut('messageManager');
            $this->messageManager = $this->createMock(ManagerInterface::class);
        }
        return $this->messageManager;
    }

    protected function expectingRequest(): RequestInterface&MockObject
    {
        if (!$this->request instanceof MockObject) {
            $this->assertNotHandedOut('request');
            $this->request = $this->createMock(RequestInterface::class);
        }
        return $this->request;
    }

    protected function expectingRedirect(): Redirect&MockObject
    {
        if (!$this->redirect instanceof MockObject) {
            $this->assertNotHandedOut('redirect');
            $this->redirect = $this->createMock(Redirect::class);
        }
        return $this->redirect;
    }

    /**
     * @template T of object
     * @param T $double
     * @return T
     */
    private function handOut(string $name, object $double): object
    {
        $this->handedOut[$name] = true;
        return $double;
    }

    private function assertNotHandedOut(string $name): void
    {
        if ($this->handedOut[$name] ?? false) {
            self::fail("The controller already holds the stub $name; call the expecting method before building it.");
        }
    }
}
