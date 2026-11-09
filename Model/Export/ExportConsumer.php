<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Export;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/** Handler of furan_formbuilder.export: writes the CSV and posts an admin notification with its download link. */
class ExportConsumer
{
    public const DIRECTORY = 'export/furan_formbuilder/queued';

    public function __construct(
        private readonly FormRepositoryInterface $formRepository,
        private readonly CsvExporter $exporter,
        private readonly Filesystem $filesystem,
        private readonly NotifierInterface $notifier,
        private readonly UrlInterface $backendUrl,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        try {
            $formId = (int)($this->json->unserialize($message)['form_id'] ?? 0);
            $form = $this->formRepository->getById($formId);
        } catch (NoSuchEntityException) {
            return;
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder export queue: ' . $e->getMessage());
            return;
        }
        try {
            $path = $this->exporter->export($form);
            $token = (string)preg_replace('#^.*/|\.csv$#', '', $path);
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $directory->create(self::DIRECTORY);
            // Out of the hourly cleanup of interrupted downloads; queued exports are kept for a day.
            $directory->renameFile($path, self::DIRECTORY . '/' . $token . '.csv');
            $this->notifier->addNotice(
                (string)__('Submissions export of "%1" is ready', $form->getTitle()),
                (string)__('The CSV file can be downloaded for 24 hours.'),
                $this->backendUrl->getUrl(
                    'furanform/submission/exportfile',
                    ['form_id' => $formId, 'file' => $token, '_nosecret' => true]
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder queued export failed for form ' . $formId . ': ' . $e->getMessage());
            $this->notifier->addMajor(
                (string)__('Submissions export of "%1" failed', $form->getTitle()),
                (string)__('See the exception log for details.')
            );
        }
    }
}
