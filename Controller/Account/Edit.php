<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Seo\FormUrls;
use Furan\FormBuilder\Model\Submission\EditToken;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;

/**
 * Sends the owner to the form with the edit token in the URL hash (a query string would make one FPC entry per token).
 * Everything that is not editable gives 404 so ids cannot be probed.
 */
class Edit implements AccountInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly Session $session,
        private readonly RequestInterface $request,
        private readonly SubmissionReader $reader,
        private readonly FormRepositoryInterface $formRepository,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly FormUrls $formUrls,
        private readonly EditToken $editToken,
        private readonly ManagerInterface $messageManager,
        private readonly Translator $translator,
        private readonly ScheduleGate $schedule,
        private readonly SubmissionPolicy $policy
    ) {
    }

    /** @return Redirect|Forward */
    public function execute()
    {
        $customerId = (int)$this->session->getCustomerId();
        $row = $customerId > 0 ? $this->reader->find($customerId, (int)$this->request->getParam('id')) : null;
        if ($row !== null) {
            $submissionId = (int)$row['submission_id'];
            $result = $this->editUrl($customerId, $submissionId, (int)$row['form_id'], (int)$row['status']);
            if (is_string($result)) {
                return $this->redirectFactory->create()->setUrl($result);
            }
            if ($result !== null) {
                // A closed form refuses edits; the owner can still view, reply and delete.
                $this->messageManager->addErrorMessage($result['closed']);
                return $this->redirectFactory->create()->setPath('furanform/account/submission', ['id' => $submissionId]);
            }
        }
        return $this->forwardFactory->create()->forward('noroute');
    }

    /** @return string|array{closed: string}|null the form URL with the edit token, the schedule message, or null (404) */
    private function editUrl(int $customerId, int $submissionId, int $formId, int $status): string|array|null
    {
        if (!$this->policy->isEditableStatus($status)) {
            return null;
        }
        try {
            $storeId = $this->submissionRepository->getById($submissionId)->getStoreId();
            if ($storeId === null) {
                return null;
            }
            $form = $this->formRepository->getActiveById($formId, $storeId);
        } catch (NoSuchEntityException) {
            return null;
        }
        if (!$this->policy->canEdit($form->getSettings(), $status)) {
            return null;
        }
        $closed = $this->schedule->refusal($this->translator->localize($form, $storeId));
        if ($closed !== null) {
            return ['closed' => $closed['message']];
        }
        return $this->formUrls->storeUrl($form, $storeId)
            . '#ffb-edit=' . $this->editToken->issue($submissionId, $customerId, $formId);
    }
}
