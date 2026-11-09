<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Observer;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Furan\FormBuilder\Observer\DeleteFormFiles;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeleteFormFilesTest extends TestCase
{
    public function testItDeletesTheWholeFormUploadDirectoryWhenAFormIsDeleted(): void
    {
        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn('15');
        $storage = $this->createMock(FileStorage::class);
        $storage->expects(self::once())->method('deleteFormFiles')->with(15);

        (new DeleteFormFiles($storage, $this->createStub(LoggerInterface::class)))->execute(
            new Observer(['event' => new Event(['data_object' => $form])])
        );
    }

    public function testItLogsInsteadOfThrowingWhenFormFileDeletionFails(): void
    {
        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn(3);
        $storage = $this->createStub(FileStorage::class);
        $storage->method('deleteFormFiles')->willThrowException(new \RuntimeException('x'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new DeleteFormFiles($storage, $logger))->execute(
            new Observer(['event' => new Event(['data_object' => $form])])
        );
    }
}
