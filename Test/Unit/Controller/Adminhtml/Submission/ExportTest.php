<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Submission\Export;
use Furan\FormBuilder\Model\Export\CsvExporter;
use Furan\FormBuilder\Model\Export\ExportQueue;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\App\ResponseInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;

class ExportTest extends SubmissionControllerTestCase
{
    /** @var array<string, string> */
    private array $headers = [];

    private function capturingResponse(): void
    {
        $response = $this->createStub(\Magento\Framework\App\Response\Http::class);
        $response->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($response) {
            $this->headers[$name] = $value;
            return $response;
        });
        $this->context->method('getResponse')->willReturn($response);
    }

    private function assertSecureDownloadHeaders(): void
    {
        $this->assertSame('nosniff', $this->headers['X-Content-Type-Options'] ?? null);
        $this->assertSame('sandbox', $this->headers['Content-Security-Policy'] ?? null);
    }

    public function testItRequiresTheExportAclResource(): void
    {
        $this->assertSame('Furan_FormBuilder::submissions_export', Export::ADMIN_RESOURCE);
        $this->assertTrue(is_subclass_of(Export::class, HttpGetActionInterface::class));
    }

    public function testItStreamsTheFileAndRemovesItAfterDownload(): void
    {
        $this->capturingResponse();
        $this->expectingRequest()->method('getParam')->with('form_id')->willReturn('7');
        $form = $this->createStub(FormInterface::class);
        $form->method('getIdentifier')->willReturn('contact');
        $repo = $this->createMock(FormRepositoryInterface::class);
        $repo->method('getById')->with(7)->willReturn($form);
        $exporter = $this->createMock(CsvExporter::class);
        $exporter->method('export')->with($form)->willReturn('export/furan_formbuilder/abc.csv');
        $response = $this->createStub(ResponseInterface::class);
        $files = $this->createMock(FileFactory::class);
        $files->expects($this->once())->method('create')->with(
            $this->matchesRegularExpression('/^form-contact-\d{8}-\d{6}\.csv$/'),
            ['type' => 'filename', 'value' => 'export/furan_formbuilder/abc.csv', 'rm' => true],
            DirectoryList::VAR_DIR,
            'text/csv'
        )->willReturn($response);

        $this->assertSame($response, (new Export($this->context, $repo, $exporter, $files, $this->createStub(LoggerInterface::class), $this->queue()))->execute());
        $this->assertSecureDownloadHeaders();
    }

    public function testItRedirectsWithAnErrorForAnUnknownForm(): void
    {
        $this->request->method('getParam')->willReturn('99');
        $repo = $this->createStub(FormRepositoryInterface::class);
        $repo->method('getById')->willThrowException(new NoSuchEntityException());
        $files = $this->createMock(FileFactory::class);
        $files->expects($this->never())->method('create');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->willReturnSelf();

        $result = (new Export($this->context, $repo, $this->createStub(CsvExporter::class), $files, $this->createStub(LoggerInterface::class), $this->queue()))->execute();
        $this->assertSame($this->redirect, $result);
    }

    public function testItRedirectsWithAnErrorAndLogsWhenTheExportFailsUnexpectedly(): void
    {
        $this->request->method('getParam')->willReturn('7');
        $repo = $this->createStub(FormRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->createStub(FormInterface::class));
        $exporter = $this->createStub(CsvExporter::class);
        $exporter->method('export')->willThrowException(new \RuntimeException('disk full'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $files = $this->createMock(FileFactory::class);
        $files->expects($this->never())->method('create');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->willReturnSelf();

        $this->assertSame($this->redirect, (new Export($this->context, $repo, $exporter, $files, $logger, $this->queue()))->execute());
    }

    private function queue(bool $large = false, bool $accepted = true): ExportQueue&Stub
    {
        return $this->configureQueue($this->createStub(ExportQueue::class), $large, $accepted);
    }

    private function assertedQueue(bool $large = false, bool $accepted = true): ExportQueue&MockObject
    {
        return $this->configureQueue($this->createMock(ExportQueue::class), $large, $accepted);
    }

    /**
     * @template T of ExportQueue&Stub
     * @param T $queue
     * @return T
     */
    private function configureQueue(ExportQueue&Stub $queue, bool $large = false, bool $accepted = true): ExportQueue&Stub
    {
        $queue->method('isLarge')->willReturn($large);
        $queue->method('request')->willReturn($accepted);
        return $queue;
    }

    public function testItQueuesALargeExportInsteadOfBuildingItInTheRequest(): void
    {
        $this->expectingRequest()->method('getParam')->with('form_id')->willReturn('7');
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $repo = $this->createStub(FormRepositoryInterface::class);
        $repo->method('getById')->willReturn($form);
        $exporter = $this->createMock(CsvExporter::class);
        $exporter->expects($this->never())->method('export');
        $queue = $this->assertedQueue(true);
        $queue->expects($this->once())->method('request')->with(7)->willReturn(true);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/', ['form_id' => 7])->willReturnSelf();

        (new Export($this->context, $repo, $exporter, $this->createStub(FileFactory::class),
            $this->createStub(LoggerInterface::class), $queue))->execute();
    }
}
