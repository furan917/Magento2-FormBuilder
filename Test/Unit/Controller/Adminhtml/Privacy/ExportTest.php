<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Privacy;

use Furan\FormBuilder\Controller\Adminhtml\Privacy\Export;
use Furan\FormBuilder\Model\Privacy\Exporter;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class ExportTest extends ControllerTestCase
{
    private Exporter $exporter;
    private FileFactory $fileFactory;
    private LoggerInterface $logger;
    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = $this->createStub(Exporter::class);
        $this->fileFactory = $this->createStub(FileFactory::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $response = $this->createStub(\Magento\Framework\App\Response\Http::class);
        $response->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($response) {
            $this->headers[$name] = $value;
            return $response;
        });
        $this->context->method('getResponse')->willReturn($response);
    }

    private function params(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(static fn (string $k) => $params[$k] ?? null);
    }

    private function controller(): Export
    {
        return new Export($this->context, $this->exporter, $this->fileFactory, $this->logger);
    }

    public function testItSendsTheCustomerZipAndDeletesItAfterwards(): void
    {
        $fileFactory = $this->fileFactory = $this->createMock(FileFactory::class);
        $exporter = $this->exporter = $this->createMock(Exporter::class);
        $this->params(['customer_id' => '7']);
        $exporter->expects($this->once())->method('exportCustomer')->with(7)->willReturn('export/furan_formbuilder/x.zip');
        $response = new \stdClass();
        $fileFactory->expects($this->once())->method('create')->with(
            $this->stringEndsWith('.zip'),
            ['type' => 'filename', 'value' => 'export/furan_formbuilder/x.zip', 'rm' => true],
            'var',
            'application/zip'
        )->willReturn($response);

        $this->assertSame($response, $this->controller()->execute());
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller());
        $this->assertSame('Furan_FormBuilder::privacy', Export::ADMIN_RESOURCE);
    }

    public function testItSendsTheZipWithNosniffAndSandboxHeaders(): void
    {
        $this->params(['customer_id' => '7']);
        $this->exporter->method('exportCustomer')->willReturn('export/furan_formbuilder/x.zip');
        $this->fileFactory->method('create')->willReturnCallback(function () {
            // Headers must already be on the shared response when FileFactory sends it.
            $this->assertSame('nosniff', $this->headers['X-Content-Type-Options'] ?? null);
            $this->assertSame('sandbox', $this->headers['Content-Security-Policy'] ?? null);
            return new \stdClass();
        });

        $this->controller()->execute();
    }

    public function testItExportsByEmailWhenNoCustomerIdIsGiven(): void
    {
        $exporter = $this->exporter = $this->createMock(Exporter::class);
        $this->params(['email' => ' a@b.c ']);
        $exporter->expects($this->once())->method('exportEmail')->with('a@b.c')->willReturn('p.zip');
        $this->fileFactory->method('create')->willReturn(new \stdClass());

        $this->controller()->execute();
    }

    public function testItRejectsARequestWithoutALookupValue(): void
    {
        $exporter = $this->exporter = $this->createMock(Exporter::class);
        $this->params([]);
        $exporter->expects($this->never())->method('exportCustomer');
        $exporter->expects($this->never())->method('exportEmail');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller()->execute());
    }

    public function testItNeverLogsTheLookupEmailWhenTheExportFails(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->params(['email' => 'secret@example.com']);
        $this->exporter->method('exportEmail')->willThrowException(new \RuntimeException('failed for secret@example.com'));
        $logger->expects($this->once())->method('error')->with(
            $this->callback(static fn ($m) => !str_contains((string)$m, 'secret@example.com')),
            $this->callback(static fn ($c) => !str_contains(json_encode($c), 'secret@example.com'))
        );
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller()->execute());
    }
}
