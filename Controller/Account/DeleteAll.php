<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Model\Account\CustomerSubmissionDeleter;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/** Deletes the customer's linked submissions except those of forms that disallow account delete. */
class DeleteAll implements AccountInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    use AccountCsrf;

    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly Session $session,
        private readonly Http $request,
        private readonly CustomerSubmissionDeleter $deleter,
        private readonly Validator $formKeyValidator,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger,
        private readonly Impersonation $impersonation
    ) {
    }

    /** @return Redirect|Forward */
    public function execute()
    {
        $customerId = (int)$this->session->getCustomerId();
        if ($customerId <= 0) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        if ($this->impersonation->isActive()) {
            $this->messageManager->addErrorMessage($this->impersonation->accountRefusal());
            return $this->toList();
        }
        if ((string)$this->request->getParam('confirm') !== '1') {
            $this->messageManager->addErrorMessage((string)__('Please confirm that you want to delete all your submissions.'));
            return $this->toList();
        }

        try {
            $result = $this->deleter->deleteAll($customerId);
            $this->messageManager->addSuccessMessage((string)__('%1 submission(s) were deleted.', $result['deleted']));
            if ($result['kept'] > 0) {
                $this->messageManager->addNoticeMessage(
                    (string)__('%1 submission(s) were kept because their forms do not allow deletion.', $result['kept'])
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Furan_FormBuilder: customer delete-all failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage((string)__('Your submissions could not be deleted.'));
        }
        return $this->toList();
    }

    private function toList(): Redirect
    {
        return $this->redirectFactory->create()->setPath('furanform/account/submissions');
    }
}
