<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Submission\ExportFile;
use Furan\FormBuilder\Model\Export\ExportConsumer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

class ExportFileTest extends SubmissionControllerTestCase
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

    private function controller(string $file, bool $exists, ?FileFactory $files = null): ExportFile
    {
        $this->request->method('getParam')->willReturnMap([['file', null, $file], ['form_id', null, '7']]);
        $directory = $this->createStub(ReadInterface::class);
        $directory->method('isFile')->willReturn($exists);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::VAR_DIR)->willReturn($directory);
        $form = $this->createStub(FormInterface::class);
        $form->method('getIdentifier')->willReturn('contact');
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('getById')->willReturn($form);
        return new ExportFile($this->context, $forms, $filesystem, $files ?? $this->createStub(FileFactory::class));
    }

    public function testItStreamsAQueuedExportAndKeepsItUntilTheDailyCleanup(): void
    {
        $this->capturingResponse();
        $token = str_repeat('b', 32);
        $response = $this->createStub(ResponseInterface::class);
        $files = $this->createMock(FileFactory::class);
        $files->expects($this->once())->method('create')->with(
            $this->matchesRegularExpression('/^form-contact-\d{8}-\d{6}\.csv$/'),
            ['type' => 'filename', 'value' => ExportConsumer::DIRECTORY . '/' . $token . '.csv', 'rm' => false],
            DirectoryList::VAR_DIR,
            'text/csv'
        )->willReturn($response);

        $this->assertSame($response, $this->controller($token, true, $files)->execute());
        $this->assertSecureDownloadHeaders();
    }

    public function testItRejectsAnythingButAnExportToken(): void
    {
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->redirect->method('setPath')->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller('../../app/etc/env', true)->execute());
    }

    public function testItSkipsTheSecretKeyOnlyForTheLowercaseActionNameTheRouterPasses(): void
    {
        $property = new \ReflectionProperty(ExportFile::class, '_publicActions');
        $this->assertSame(['exportfile'], $property->getDefaultValue());
    }
}
