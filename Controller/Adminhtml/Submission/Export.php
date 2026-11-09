<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Controller\Adminhtml\SecureDownload;
use Furan\FormBuilder\Model\Export\CsvExporter;
use Furan\FormBuilder\Model\Export\ExportQueue;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Psr\Log\LoggerInterface;

class Export extends Action implements HttpGetActionInterface
{
    use ReturnsRedirect;
    use SecureDownload;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_export';

    public function __construct(
        Context $context,
        private readonly FormRepositoryInterface $formRepository,
        private readonly CsvExporter $exporter,
        private readonly FileFactory $fileFactory,
        private readonly LoggerInterface $logger,
        private readonly ExportQueue $exportQueue
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $form = $this->formRepository->getById((int)$this->getRequest()->getParam('form_id'));
            if ($this->exportQueue->isLarge((int)$form->getId())) {
                return $this->queue((int)$form->getId());
            }
            $path = $this->exporter->export($form);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('The submissions could not be exported.'));
            return $this->redirectResult()->setPath('*/*/');
        }

        $this->secureDownloadHeaders();
        // 'rm' => true deletes the temporary file from var/ after sending it.
        return $this->fileFactory->create(
            sprintf('form-%s-%s.csv', $form->getIdentifier(), gmdate('Ymd-His')),
            ['type' => 'filename', 'value' => $path, 'rm' => true],
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }

    /** A request cannot stream millions of rows before proxies and PHP time it out. */
    private function queue(int $formId): Redirect
    {
        if ($this->exportQueue->request($formId)) {
            $this->messageManager->addSuccessMessage(
                __('The export is being prepared in the background. A system message with the download link appears when it is ready.')
            );
        } else {
            $this->messageManager->addErrorMessage(__('The submissions could not be exported.'));
        }
        return $this->redirectResult()->setPath('*/*/', ['form_id' => $formId]);
    }
}
