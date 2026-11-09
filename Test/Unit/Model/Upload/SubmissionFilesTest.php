<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Furan\FormBuilder\Model\Upload\FileValidator;
use Furan\FormBuilder\Model\Upload\SubmissionFiles;
use Furan\FormBuilder\Model\Upload\UploadBudget;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubmissionFilesTest extends TestCase
{
    private function files(FileStorage $storage, LoggerInterface $logger, bool $budgetLeft = true): SubmissionFiles
    {
        $validator = $this->createStub(FileValidator::class);
        $validator->method('maxBytes')->willReturn(1000);
        $budget = $this->createStub(UploadBudget::class);
        $budget->method('reserve')->willReturn($budgetLeft);

        return new SubmissionFiles(
            $storage,
            $validator,
            $budget,
            new Pool(['file' => new File(), 'text' => new Text()]),
            $logger
        );
    }

    public function testItDeletesStoredFilesWhenALaterStepFails(): void
    {
        $storage = $this->createMock(FileStorage::class);
        $logger = $this->createMock(LoggerInterface::class);
        $files = $this->files($storage, $logger);
        $deleted = [];
        $storage->expects($this->exactly(2))->method('delete')->willReturnCallback(function (string $path) use (&$deleted): void {
            $deleted[] = $path;
            if ($path === 'a/one.txt') {
                throw new \RuntimeException('gone');
            }
        });
        $logger->expects($this->once())->method('error');

        $files->delete(['a/one.txt', 'b/two.txt']);

        $this->assertSame(['a/one.txt', 'b/two.txt'], $deleted);
    }

    public function testItJudgesTheRequestSizeByContentLengthOrByTheAcceptedFiles(): void
    {
        $files = $this->files($this->createStub(FileStorage::class), $this->createStub(LoggerInterface::class));
        $fields = [['name' => 'cv', 'type' => 'file'], ['name' => 'n', 'type' => 'text']];
        $limit = 1048576 + 1000;

        $this->assertTrue($files->isTooLarge($fields, $limit + 1, []));
        $this->assertFalse($files->isTooLarge($fields, $limit, []));
        $this->assertTrue($files->isTooLarge($fields, 0, ['cv' => ['size' => $limit + 1]]));
        $this->assertFalse($files->isTooLarge($fields, 0, []));
    }
}
