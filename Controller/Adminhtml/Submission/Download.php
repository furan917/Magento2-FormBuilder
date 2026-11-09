<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Controller\Adminhtml\SecureDownload;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;

class Download extends Action implements HttpGetActionInterface
{
    use ReturnsRedirect;
    use SecureDownload;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_files';

    public function __construct(
        Context $context,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly FileStorage $fileStorage,
        private readonly FileFactory $fileFactory,
        private readonly Pool $fieldTypes
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        try {
            $payload = $this->submissionRepository->getById($id)->getPayload();
            $file = $this->findFile($payload, (string)$this->getRequest()->getParam('field'));
            if ($file === null) {
                throw new LocalizedException(__('The requested file does not exist.'));
            }
            $path = $this->fileStorage->downloadPath($file['file']);
        } catch (LocalizedException) {
            $this->messageManager->addErrorMessage(__('The requested file could not be downloaded.'));
            return $this->redirectResult()->setPath('*/*/view', ['id' => $id]);
        }

        // FileFactory writes its own Content-Disposition, so only a header-safe ASCII name can be offered.
        $name = (string)($file['name'] ?? '');
        $ascii = trim((string)preg_replace('/[^A-Za-z0-9._-]/', '_', $name), '.');
        $this->secureDownloadHeaders();

        return $this->fileFactory->create(
            $ascii === '' ? 'download' : $ascii,
            ['type' => 'filename', 'value' => $path],
            FileStorage::DIRECTORY,
            'application/octet-stream'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $payload
     * @return array{file: string, name?: string}|null
     */
    private function findFile(array $payload, string $field): ?array
    {
        foreach ($payload as $entry) {
            if (($entry['name'] ?? null) === $field && $this->fieldTypes->isFile((string)($entry['type'] ?? ''))) {
                $value = $entry['value'] ?? null;
                return is_array($value) && is_string($value['file'] ?? null) ? $value : null;
            }
        }
        return null;
    }
}
