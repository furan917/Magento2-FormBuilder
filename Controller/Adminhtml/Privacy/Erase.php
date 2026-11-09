<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Privacy;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Privacy\Eraser;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Erase extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::privacy';

    public function __construct(
        Context $context,
        private readonly Eraser $eraser,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->redirectResult()->setPath('*/*/');
        $customerId = (int)$this->getRequest()->getParam('customer_id');
        $email = trim((string)$this->getRequest()->getParam('email'));
        try {
            if ($this->getRequest()->getParam('confirm') !== '1') {
                throw new LocalizedException(__('Tick the confirmation box to erase the personal data.'));
            }
            if ($customerId > 0) {
                $count = $this->eraser->eraseCustomer($customerId);
            } elseif ($email !== '') {
                $count = $this->eraser->eraseEmail($email);
            } else {
                throw new LocalizedException(__('Enter a customer ID or an email address.'));
            }
            $this->messageManager->addSuccessMessage(__(
                '%1 submission(s) were erased. Their uploaded files are removed from disk within the hour.',
                $count
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            // The message is not logged for the same reason the email is not: it may carry the lookup value.
            $this->logger->error('Privacy erase failed.', ['exception' => $e::class]);
            $this->messageManager->addErrorMessage(__('The personal data could not be erased.'));
        }
        return $redirect;
    }
}
