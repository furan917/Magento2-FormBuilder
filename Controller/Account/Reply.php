<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Thread\CustomerReply;
use Furan\FormBuilder\Model\Thread\ReplyOutcome;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;

/** Foreign and unknown ids give 404. */
class Reply implements AccountInterface, HttpPostActionInterface, CsrfAwareActionInterface
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
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly CustomerReply $reply,
        private readonly Validator $formKeyValidator,
        private readonly ManagerInterface $messageManager
    ) {
    }

    /** @return Redirect|Forward|Json */
    public function execute()
    {
        $customerId = (int)$this->session->getCustomerId();
        $row = $customerId > 0 ? $this->reader->find($customerId, (int)$this->request->getParam('id')) : null;
        $loaded = $row === null ? null : $this->load((int)$row['submission_id']);
        if ($row === null || $loaded === null) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        [$submission, $form] = $loaded;
        $outcome = $this->reply->postAsCustomer(
            $submission,
            $form,
            (string)$this->request->getParam('body'),
            (string)$this->session->getCustomerData()->getEmail(),
            isset($row['store_id']) ? (int)$row['store_id'] : null,
            $customerId
        );

        return $this->respond($outcome, (int)$row['submission_id']);
    }

    /** @return array{SubmissionInterface, FormInterface}|null */
    private function load(int $submissionId): ?array
    {
        try {
            $submission = $this->submissionRepository->getById($submissionId);

            return [$submission, $this->formRepository->getById($submission->getFormId())];
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function respond(ReplyOutcome $outcome, int $submissionId): Redirect|Json
    {
        $ajax = $this->request->isXmlHttpRequest();
        if ($ajax) {
            $result = $this->jsonFactory->create()->setData(['success' => $outcome->isSent(), 'message' => $outcome->message]);

            return $outcome->isSent() ? $result : $result->setHttpResponseCode($outcome->code);
        }
        if ($outcome->isSent()) {
            $this->messageManager->addSuccessMessage($outcome->message);
        } else {
            $this->messageManager->addErrorMessage($outcome->message);
        }

        return $this->toSubmission($submissionId);
    }

    private function toSubmission(int $submissionId): Redirect
    {
        return $this->redirectFactory->create()->setPath('furanform/account/submission', ['id' => $submissionId]);
    }
}
