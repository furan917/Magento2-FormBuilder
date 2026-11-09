<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Controller\Account\Export;
use Furan\FormBuilder\Model\Privacy\Exporter;
use Magento\Customer\Model\Session;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExportTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private Exporter&Stub $exporter;
    private FileFactory&Stub $files;
    private MemoryCounter $counter;
    private HttpResponse&Stub $response;
    /** @var array<string, string> */
    private array $headers = [];
    private Validator&Stub $formKey;
    private Http&Stub $request;
    private Forward&Stub $forward;
    private Redirect&Stub $redirect;
    private Json&Stub $json;
    private ManagerInterface&Stub $messages;
    private Export $controller;

    private function build(mixed $customerId = '5', bool $throttled = false, bool $impersonating = false): void
    {
        $this->exporter = $this->double('exporter', Exporter::class);
        $this->files = $this->double('files', FileFactory::class);
        $this->counter = new MemoryCounter();
        if ($throttled) {
            $this->counter->hit('ffb_export_5', 300);
        }
        $this->response = $this->createStub(HttpResponse::class);
        $this->response->method('setHeader')->willReturnCallback(function (string $name, string $value) {
            $this->headers[$name] = $value;
            return $this->response;
        });
        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturn($impersonating);
        $impersonation->method('accountRefusal')->willReturn('Not while impersonating.');
        $this->formKey = $this->createStub(Validator::class);
        $this->request = $this->createStub(Http::class);
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

        $this->controller = new Export(
            $redirectFactory,
            $forwardFactory,
            $jsonFactory,
            $session,
            $this->request,
            $this->exporter,
            $this->files,
            $this->counter,
            $this->formKey,
            $this->messages,
            $this->createStub(LoggerInterface::class),
            $impersonation,
            $this->response
        );
    }

    public function testItDownloadsTheCustomersDataAndUploadedFilesAsOneZip(): void
    {
        $this->asserted = ['exporter', 'files'];
        $this->build();
        $this->exporter->expects($this->once())->method('exportCustomer')->with(5)
            ->willReturn('export/furan_formbuilder/x.zip');
        $response = $this->createStub(ResponseInterface::class);
        $this->files->expects($this->once())->method('create')->with(
            $this->matchesRegularExpression('/^my-form-submissions-[\d-]+\.zip$/'),
            ['type' => 'filename', 'value' => 'export/furan_formbuilder/x.zip', 'rm' => true],
            'var',
            'application/zip'
        )->willReturn($response);

        $this->assertSame($response, $this->controller->execute());
        $this->assertSame(1, $this->counter->peek('ffb_export_5'));
        $this->assertSame('nosniff', $this->headers['X-Content-Type-Options']);
        $this->assertSame('sandbox', $this->headers['Content-Security-Policy']);
    }

    public function testItCountsAFailedExportTowardsTheCooldown(): void
    {
        $this->asserted = ['exporter'];
        $this->build();
        $this->exporter->expects($this->once())->method('exportCustomer')->willThrowException(new \RuntimeException('x'));
        $this->controller->execute();

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItRefusesTheExportWhileAnAdminIsLoggedInAsTheCustomerBeforeTheCooldown(): void
    {
        $this->asserted = ['exporter', 'messages'];
        $this->build('5', false, true);
        $this->exporter->expects($this->never())->method('exportCustomer');
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Not while impersonating.');

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame([], $this->counter->rows);
    }

    public function testItCoversOnlySubmissionsLinkedToTheCustomerAccount(): void
    {
        $this->asserted = ['exporter', 'forward'];
        $this->build(0);
        $this->exporter->expects($this->never())->method('exportCustomer');
        $this->exporter->expects($this->never())->method('exportEmail');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRefusesARepeatedExportInsideTheCooldown(): void
    {
        $this->asserted = ['exporter', 'messages'];
        $this->build('5', true);
        $this->exporter->expects($this->never())->method('exportCustomer');
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
}
