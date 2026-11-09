<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Export;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Export\CsvExporter;
use Furan\FormBuilder\Model\Export\ExportConsumer;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExportConsumerTest extends TestCase
{
    public function testItWritesTheCsvKeepsItForADayAndNotifiesWithTheDownloadLink(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact');
        $forms = $this->createMock(FormRepositoryInterface::class);
        $forms->method('getById')->with(7)->willReturn($form);
        $token = str_repeat('a', 32);
        $exporter = $this->createMock(CsvExporter::class);
        $exporter->method('export')->with($form)->willReturn('export/furan_formbuilder/' . $token . '.csv');
        $directory = $this->createMock(WriteInterface::class);
        $directory->expects($this->once())->method('renameFile')
            ->with('export/furan_formbuilder/' . $token . '.csv', ExportConsumer::DIRECTORY . '/' . $token . '.csv');
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')
            ->with('furanform/submission/exportfile', ['form_id' => 7, 'file' => $token, '_nosecret' => true])
            ->willReturn('https://admin.test/export');
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addNotice')
            ->with($this->stringContains('Contact'), $this->anything(), 'https://admin.test/export');

        (new ExportConsumer($forms, $exporter, $filesystem, $notifier, $url, new Json(), $this->createStub(LoggerInterface::class)))
            ->process('{"form_id":7}');
    }

    public function testItDropsTheMessageForADeletedFormAndReportsAFailedExport(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('getById')->willReturnCallback(function (int $id) {
            if ($id === 1) {
                throw new NoSuchEntityException(__('gone'));
            }
            return $this->createStub(FormInterface::class);
        });
        $exporter = $this->createMock(CsvExporter::class);
        $exporter->expects($this->once())->method('export')->willThrowException(new \RuntimeException('disk full'));
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addMajor');
        $consumer = new ExportConsumer($forms, $exporter, $this->createStub(Filesystem::class), $notifier,
            $this->createStub(UrlInterface::class), new Json(), $this->createStub(LoggerInterface::class));

        $consumer->process('{"form_id":1}');
        $consumer->process('{"form_id":2}');
    }
}
