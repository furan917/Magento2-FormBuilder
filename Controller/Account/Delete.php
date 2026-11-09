<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Account\CustomerSubmissionDeleter;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Furan\FormBuilder\Model\Account\SubmissionReader;
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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/** Unknown, foreign and not-deletable ids all give 404 so ids cannot be probed. */
class Delete implements AccountInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    use AccountCsrf;

    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly Session $session,
        private readonly Http $request,
        private readonly SubmissionReader $reader,
        private readonly FormRepositoryInterface $formRepository,
        private readonly SubmissionPolicy $policy,
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
        $row = $customerId > 0 ? $this->reader->find($customerId, (int)$this->request->getParam('id')) : null;
        if ($row === null || !$this->isDeletable((int)$row['form_id'])) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        if ($this->impersonation->isActive()) {
            $this->messageManager->addErrorMessage($this->impersonation->accountRefusal());
            return $this->toList();
        }
        try {
            $this->deleter->delete((int)$row['submission_id']);
            $this->messageManager->addSuccessMessage((string)__('Your submission was deleted.'));
        } catch (\Throwable $e) {
            $this->logger->error('Furan_FormBuilder: customer delete failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage((string)__('Your submission could not be deleted.'));
        }
        return $this->toList();
    }

    private function isDeletable(int $formId): bool
    {
        try {
            return $this->policy->canDelete($this->formRepository->getById($formId)->getSettings());
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    private function toList(): Redirect
    {
        return $this->redirectFactory->create()->setPath('furanform/account/submissions');
    }
}
