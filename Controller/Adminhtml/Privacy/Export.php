<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Privacy;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Privacy\Exporter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Export extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::privacy';

    public function __construct(
        Context $context,
        private readonly Exporter $exporter,
        private readonly FileFactory $fileFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $customerId = (int)$this->getRequest()->getParam('customer_id');
        $email = trim((string)$this->getRequest()->getParam('email'));
        try {
            if ($customerId > 0) {
                $path = $this->exporter->exportCustomer($customerId);
            } elseif ($email !== '') {
                $path = $this->exporter->exportEmail($email);
            } else {
                throw new LocalizedException(__('Enter a customer ID or an email address.'));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectResult()->setPath('*/*/');
        } catch (\Throwable $e) {
            // The message is not logged for the same reason the email is not: it may carry the lookup value.
            $this->logger->error('Privacy export failed.', ['exception' => $e::class]);
            $this->messageManager->addErrorMessage(__('The personal data could not be exported.'));
            return $this->redirectResult()->setPath('*/*/');
        }

        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHeader('Content-Security-Policy', 'sandbox', true);
        // 'rm' => true deletes the zip from var/ after sending it.
        return $this->fileFactory->create(
            sprintf('personal-data-%s.zip', gmdate('Ymd-His')),
            ['type' => 'filename', 'value' => $path, 'rm' => true],
            DirectoryList::VAR_DIR,
            'application/zip'
        );
    }
}
