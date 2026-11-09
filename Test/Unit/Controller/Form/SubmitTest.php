<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Form\Submit;
use Furan\FormBuilder\Model\Submission\Processor;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Submission\Result;
use Furan\FormBuilder\Model\Submission\SubmissionInputFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubmitTest extends TestCase
{
    private FormRepositoryInterface&Stub $repository;
    private Processor&Stub $processor;
    private SubmissionInputFactory&Stub $inputFactory;
    private SubmissionInput $input;
    private Validator&Stub $formKeyValidator;
    private LoggerInterface&Stub $logger;
    private Http&MockObject $request;
    private Json&Stub $json;
    private Submit $controller;
    /** @var array<string, string> */
    private array $headers = [];
    private ?int $code = null;
    private mixed $data = null;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?Validator $formKeyValidator = null, ?LoggerInterface $logger = null, ?Processor $processor = null, ?FormRepositoryInterface $repository = null): void
    {
        $this->repository = $repository ?? $this->createStub(FormRepositoryInterface::class);
        $this->processor = $processor ?? $this->createStub(Processor::class);
        $this->input = new SubmissionInput(formToken: 'tok');
        $this->inputFactory = $this->createStub(SubmissionInputFactory::class);
        $this->inputFactory->method('fromHttp')->willReturnCallback(fn (): SubmissionInput => $this->input);
        $this->formKeyValidator = $formKeyValidator ?? $this->createStub(Validator::class);
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $this->request = $this->createMock(Http::class);
        $this->request->method('getParam')->with('form_id')->willReturn('12');
        $this->json = $this->createStub(Json::class);
        $this->json->method('setHeader')->willReturnCallback(function (string $n, string $v) {
            $this->headers[$n] = $v;
            return $this->json;
        });
        $this->json->method('setHttpResponseCode')->willReturnCallback(function (int $c) {
            $this->code = $c;
            return $this->json;
        });
        $this->json->method('setData')->willReturnCallback(function ($d) {
            $this->data = $d;
            return $this->json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->controller = new Submit(
            $jsonFactory,
            $this->request,
            $this->repository,
            $storeManager,
            $this->processor,
            $this->inputFactory,
            $this->formKeyValidator,
            $this->logger
        );
    }

    public function testItValidatesTheFormKeyExplicitlyForAjaxRequests(): void
    {
        $formKeyValidator = $this->createMock(Validator::class);
        $this->fixture(formKeyValidator: $formKeyValidator);
        $this->request->method('isXmlHttpRequest')->willReturn(true);
        $formKeyValidator->expects($this->exactly(2))->method('validate')
            ->with($this->request)->willReturnOnConsecutiveCalls(true, false);

        $this->assertTrue($this->controller->validateForCsrf($this->request));
        $this->assertFalse($this->controller->validateForCsrf($this->request));
    }

    public function testItReturnsAJson403WhenTheFormKeyIsInvalid(): void
    {
        $exception = $this->controller->createCsrfValidationException($this->request);

        $this->assertInstanceOf(InvalidRequestException::class, $exception);
        $this->assertSame($this->json, $exception->getReplaceResult());
        $this->assertSame(403, $this->code);
        $this->assertSame([
            'success' => false,
            'message' => 'Your session has expired. Please refresh the page and try again.',
            'errors' => [],
            'redirect' => null,
        ], $this->data);
        $this->assertSame('noindex', $this->headers['X-Robots-Tag']);
    }

    public function testItReturns404JsonForAnUnknownOrInactiveForm(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $processor = $this->createMock(Processor::class);
        $this->fixture(repository: $repository, processor: $processor);
        $repository->method('getActiveById')->with(12, 3)
            ->willThrowException(new NoSuchEntityException(__('nope')));
        $processor->expects($this->never())->method('process');

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(404, $this->code);
        $this->assertSame('This form is no longer available.', $this->data['message']);
        $this->assertFalse($this->data['success']);
    }

    public function testItReturns500JsonWithoutLeakingUnexpectedErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->repository->method('getActiveById')->willThrowException(new \RuntimeException('db secret'));
        $logger->expects($this->once())->method('error');

        $this->controller->execute();
        $this->assertSame(500, $this->code);
        $this->assertStringNotContainsString('secret', (string)json_encode($this->data));
    }

    public function testItReturnsTheProcessorResultAsJsonWithItsStatusCode(): void
    {
        $processor = $this->createMock(Processor::class);
        $this->fixture(processor: $processor);
        $form = $this->createStub(FormInterface::class);
        $this->repository->method('getActiveById')->willReturn($form);
        $processor->expects($this->once())->method('process')->with($form, $this->input)
            ->willReturn(Result::invalid(['email' => 'Bad']));

        $this->controller->execute();
        $this->assertSame(422, $this->code);
        $this->assertSame(
            ['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => ['email' => 'Bad'], 'redirect' => null],
            $this->data
        );
    }

    public function testItSendsNoStoreAndNoindexHeaders(): void
    {
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->processor->method('process')->willReturn(Result::success('Thanks', '/thanks', null));

        $this->controller->execute();
        $this->assertSame('/thanks', $this->data['redirect']);
        $this->assertStringContainsString('no-store', $this->headers['Cache-Control']);
        $this->assertSame('noindex', $this->headers['X-Robots-Tag']);
    }

    public function testItReturnsTheReferenceInTheSubmitResponse(): void
    {
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->processor->method('process')
            ->willReturn(Result::success('Thanks', null, '0194f3a0-1111-7222-8333-444455556666'));

        $this->controller->execute();

        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $this->data['reference']);
    }

    public function testItAddsNoReferenceKeyToAFailure(): void
    {
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->processor->method('process')->willReturn(Result::refused('No', 422));

        $this->controller->execute();

        $this->assertArrayNotHasKey('reference', $this->data);
    }
}
