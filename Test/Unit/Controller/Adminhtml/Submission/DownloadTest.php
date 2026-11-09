<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Submission\Download;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\Attributes\DataProvider;

class DownloadTest extends SubmissionControllerTestCase
{
    private const PATH = 'furan_formbuilder/3/2026/01/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf';

    private array $headers = [];
    private ?array $streamed = null;
    private HttpResponse $sent;

    public function testItTreatsACustomTypeWhoseIsFileReturnsTrueAsAFileInAdminDownload(): void
    {
        $storage = $this->createMock(FileStorage::class);
        $storage->expects($this->once())->method('downloadPath')->with(self::PATH)->willReturn(self::PATH);
        $file = ['file' => self::PATH, 'name' => 'a.pdf', 'size' => 7, 'mime' => 'application/pdf'];

        $result = $this->controller([['name' => 'cv', 'label' => 'CV', 'type' => 'doc', 'value' => $file]], 'cv', $storage)
            ->execute();

        $this->assertSame($this->sent, $result);
    }

    public function testItStreamsAFileAsAnAttachmentWithNosniffAndSandboxHeaders(): void
    {
        $storage = $this->createMock(FileStorage::class);
        $storage->expects($this->once())->method('downloadPath')->with(self::PATH)->willReturn(self::PATH);
        $storage->expects($this->never())->method('copyToLocal');
        $file = ['file' => self::PATH, 'name' => "ev\"il\r\nX: y é.pdf", 'size' => 7, 'mime' => 'application/pdf'];

        $result = $this->controller([['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => $file]], 'cv', $storage)
            ->execute();

        $this->assertSame($this->sent, $result);
        $this->assertSame(
            ['ev_il__X__y___.pdf', ['type' => 'filename', 'value' => self::PATH], DirectoryList::VAR_IMPORT_EXPORT,
                'application/octet-stream'],
            $this->streamed
        );
        $this->assertSame('nosniff', $this->headers['X-Content-Type-Options']);
        $this->assertSame('sandbox', $this->headers['Content-Security-Policy']);
        $this->assertSame('private, no-store', $this->headers['Cache-Control']);
    }

    #[DataProvider('nonFileProvider')]
    public function testItRefusesToDownloadAFieldThatIsNotAFileInThePayload(array $payload, string $field): void
    {
        $storage = $this->createMock(FileStorage::class);
        $storage->expects($this->never())->method('downloadPath');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')
            ->with('*/*/view', ['id' => 5])->willReturnSelf();

        $result = $this->controller($payload, $field, $storage)->execute();

        $this->assertSame($this->redirect, $result);
        $this->assertNull($this->streamed);
    }

    public static function nonFileProvider(): array
    {
        return [
            'text field' => [[['name' => 'a', 'type' => 'text', 'label' => 'A', 'value' => self::PATH]], 'a'],
            'missing field' => [[], 'a'],
            'malformed file value' => [[['name' => 'a', 'type' => 'file', 'label' => 'A', 'value' => 'x']], 'a'],
            'file value without path' => [[['name' => 'a', 'type' => 'file', 'label' => 'A', 'value' => ['name' => 'x']]], 'a'],
        ];
    }

    public function testItRedirectsWhenTheStoredFileCannotBeRead(): void
    {
        $storage = $this->createStub(FileStorage::class);
        $storage->method('downloadPath')->willThrowException(new LocalizedException(__('Invalid file path.')));
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->redirect->method('setPath')->willReturnSelf();
        $file = ['file' => '../../etc/passwd', 'name' => 'x', 'size' => 1, 'mime' => ''];

        $result = $this->controller([['name' => 'a', 'type' => 'file', 'label' => 'A', 'value' => $file]], 'a', $storage)
            ->execute();

        $this->assertSame($this->redirect, $result);
    }

    private function controller(array $payload, string $field, FileStorage $storage): Download
    {
        $this->request->method('getParam')->willReturnMap([['id', null, '5'], ['field', null, $field]]);
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getPayload')->willReturn($payload);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $repository->method('getById')->with(5)->willReturn($submission);

        $response = $this->createStub(HttpResponse::class);
        $response->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($response) {
            $this->headers[$name] = $value;
            return $response;
        });
        $this->context->method('getResponse')->willReturn($response);
        $this->sent = $this->createStub(HttpResponse::class);
        $fileFactory = $this->createStub(FileFactory::class);
        $fileFactory->method('create')->willReturnCallback(function (...$args) {
            $this->streamed = array_slice($args, 0, 4);
            return $this->sent;
        });

        return new Download($this->context, $repository, $storage, $fileFactory, FieldPools::withFiles());
    }
}
