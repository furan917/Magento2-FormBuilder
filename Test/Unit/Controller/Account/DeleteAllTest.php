<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Controller\Account\DeleteAll;
use Furan\FormBuilder\Model\Account\CustomerSubmissionDeleter;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Psr\Log\LoggerInterface;

class DeleteAllTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private CustomerSubmissionDeleter&Stub $deleter;
    private Validator&Stub $formKey;
    private Http&MockObject $request;
    private Forward&Stub $forward;
    private Redirect&Stub $redirect;
    private Json&Stub $json;
    private ManagerInterface&Stub $messages;
    private DeleteAll $controller;
    private bool $impersonating = false;

    private function impersonation(): Impersonation
    {
        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturn($this->impersonating);
        $impersonation->method('accountRefusal')->willReturn('Not while impersonating.');
        return $impersonation;
    }

    private function build(mixed $customerId = '5', mixed $confirm = '1'): void
    {
        $this->deleter = $this->double('deleter', CustomerSubmissionDeleter::class);
        $this->formKey = $this->createStub(Validator::class);
        $this->request = $this->createMock(Http::class);
        $this->request->method('getParam')->with('confirm')->willReturn($confirm);
        $this->forward = $this->double('forward', Forward::class);
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($this->forward);
        $this->redirect = $this->createStub(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $this->json = $this->double('json', Json::class);
        $this->json->method('setHttpResponseCode')->willReturnSelf();
        $this->json->method('setData')->willReturnSelf();
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $this->messages = $this->double('messages', ManagerInterface::class);
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn($customerId);

        $this->controller = new DeleteAll(
            $redirectFactory,
            $forwardFactory,
            $jsonFactory,
            $session,
            $this->request,
            $this->deleter,
            $this->formKey,
            $this->messages,
            $this->createStub(LoggerInterface::class),
            $this->impersonation()
        );
    }

    public function testItLetsACustomerDeleteAllTheirSubmissions(): void
    {
        $this->asserted = ['deleter', 'messages'];
        $this->build();
        $this->deleter->expects($this->once())->method('deleteAll')->with(5)->willReturn(['deleted' => 7, 'kept' => 2]);
        $this->messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->stringContains('7'));
        $this->messages->expects($this->once())->method('addNoticeMessage')
            ->with($this->stringContains('2'));

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItAddsNoKeptNoticeWhenEverythingWasDeleted(): void
    {
        $this->asserted = ['messages'];
        $this->build();
        $this->deleter->method('deleteAll')->willReturn(['deleted' => 3, 'kept' => 0]);
        $this->messages->expects($this->once())->method('addSuccessMessage');
        $this->messages->expects($this->never())->method('addNoticeMessage');

        $this->controller->execute();
    }

    public function testItReportsAFailedDeleteAll(): void
    {
        $this->asserted = ['messages'];
        $this->build();
        $this->deleter->method('deleteAll')->willThrowException(new \RuntimeException('down'));
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItNotFoundsGuests(): void
    {
        $this->asserted = ['deleter', 'forward'];
        $this->build(0);
        $this->deleter->expects($this->never())->method('deleteAll');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItDeletesNothingWithoutTheConfirmation(): void
    {
        $this->asserted = ['deleter', 'messages'];
        $this->build('5', null);
        $this->deleter->expects($this->never())->method('deleteAll');
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItRejectsAnAjaxExportOrDeleteWithoutAFormKey(): void
    {
        $this->asserted = ['json'];
        $this->build();
        $this->formKey->method('validate')->willReturn(false);
        $this->request->method('isXmlHttpRequest')->willReturn(true);
        $this->json->expects($this->once())->method('setHttpResponseCode')->with(403);

        $this->assertFalse($this->controller->validateForCsrf($this->request));
        $this->assertInstanceOf(
            InvalidRequestException::class,
            $this->controller->createCsrfValidationException($this->request)
        );
    }

    public function testItRefusesDeleteAllWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $this->asserted = ['deleter', 'messages'];
        $this->impersonating = true;
        $this->build();
        $this->deleter->expects($this->never())->method('deleteAll');
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Not while impersonating.');

        $this->assertSame($this->redirect, $this->controller->execute());
    }
}
