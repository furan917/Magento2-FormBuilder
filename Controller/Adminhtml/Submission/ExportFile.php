<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Controller\Adminhtml\SecureDownload;
use Furan\FormBuilder\Model\Export\ExportConsumer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem;

/** Download of a CSV built by the export queue; the link comes from an admin notification. */
class ExportFile extends Action implements HttpGetActionInterface
{
    use ReturnsRedirect;
    use SecureDownload;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_export';

    // Notification links carry no secret key; login and the ACL above still apply, and the request is read-only.
    /** @var string[] */
    protected $_publicActions = ['exportfile'];

    public function __construct(
        Context $context,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Filesystem $filesystem,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $token = (string)$this->getRequest()->getParam('file');
        $path = ExportConsumer::DIRECTORY . '/' . $token . '.csv';
        try {
            if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1
                || !$this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->isFile($path)
            ) {
                throw new \RuntimeException('missing');
            }
            $identifier = $this->formRepository->getById((int)$this->getRequest()->getParam('form_id'))->getIdentifier();
        } catch (\Throwable) {
            $this->messageManager->addErrorMessage(__('This export is no longer available. Please export again.'));
            return $this->redirectResult()->setPath('*/*/');
        }

        $this->secureDownloadHeaders();
        return $this->fileFactory->create(
            sprintf('form-%s-%s.csv', $identifier, gmdate('Ymd-His')),
            ['type' => 'filename', 'value' => $path, 'rm' => false],
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }
}
