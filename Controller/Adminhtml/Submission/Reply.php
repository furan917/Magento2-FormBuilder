<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Thread\AdminReply;
use Furan\FormBuilder\Model\Thread\MessageSanitizer;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\RecipientResolver;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;

class Reply extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_reply';

    public function __construct(
        Context $context,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly FormRepositoryInterface $formRepository,
        private readonly RecipientResolver $recipients,
        private readonly MessageSanitizer $sanitizer,
        private readonly AdminReply $reply
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->redirectResult();
        try {
            $submission = $this->submissionRepository->getById($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This submission no longer exists.'));
            return $redirect->setPath('*/*/');
        }
        $redirect->setPath('*/*/view', ['id' => $id]);

        $body = $this->sanitizer->clean((string)$this->getRequest()->getParam('body'));
        $length = mb_strlen($body);
        if ($length === 0 || $length > Messages::MAX_BODY_LENGTH) {
            $this->messageManager->addErrorMessage(
                __('Please enter a reply of up to %1 characters.', Messages::MAX_BODY_LENGTH)
            );
            return $redirect;
        }
        $form = $this->formRepository->getById($submission->getFormId());
        $recipient = $this->recipients->resolve($submission, $form);
        if ($recipient === null) {
            $this->messageManager->addErrorMessage(__('This submission has no valid email address to reply to.'));
            return $redirect;
        }

        /** @var User|null $user */
        $user = $this->_auth->getUser();
        try {
            $this->reply->send($submission, $form, $recipient, $body, $user ? (int)$user->getId() : null);
            $this->messageManager->addSuccessMessage(__('Your reply has been sent.'));
        } catch (MailException) {
            $this->messageManager->addErrorMessage(
                __('The reply was saved but the email could not be sent. Please check the mail settings.')
            );
        }

        return $redirect;
    }
}
