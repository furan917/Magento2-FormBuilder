<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Translate\Inline\StateInterface;

class AdminReplyMail
{
    public function __construct(
        private readonly TransportBuilderFactory $transportBuilderFactory,
        private readonly StateInterface $inlineTranslation,
        private readonly SubmissionStore $submissionStore,
        private readonly Config $config,
        private readonly MessageRenderer $renderer,
        private readonly ThreadAccess $threadAccess,
        private readonly ThreadHeaders $threadHeaders,
        private readonly ReplyPolicy $policy,
        private readonly StoreScope $storeScope,
        private readonly Translator $translator
    ) {
    }

    /** @throws MailException */
    public function send(SubmissionInterface $submission, FormInterface $form, string $recipient, string $body): void
    {
        $storeId = $this->submissionStore->resolve($submission, $form);
        $this->storeScope->run($storeId, fn () => $this->sendAsStore($submission, $form, $recipient, $body, $storeId));
    }

    /** Runs as the submission's store, so the subject and form title use the customer's language. */
    private function sendAsStore(
        SubmissionInterface $submission,
        FormInterface $form,
        string $recipient,
        string $body,
        int $storeId
    ): void {
        $form = $this->translator->localize($form, $storeId);
        $title = trim((string)preg_replace('/[\r\n]+/', ' ', $form->getTitle()));
        $replyTo = $this->config->getReplyTo($storeId);
        $settings = $form->getSettings();
        $repliesOpen = (int)$submission->getCustomerId() > 0
            ? $this->policy->customerRepliesOpen($settings, $storeId)
            : $this->policy->guestRepliesOpen($settings, $storeId);
        $repliesOpen = $repliesOpen && !$this->policy->isFull((int)$submission->getId());
        $replyUrl = $repliesOpen ? $this->threadAccess->replyUrl($submission, $recipient) : '';

        $this->inlineTranslation->suspend();
        try {
            $builder = $this->transportBuilderFactory->create()
                ->setTemplateIdentifier($this->config->getEmailTemplate('reply', $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'subject' => MessageRenderer::braces((string)__('Re: %1', $title)),
                    'body_html' => $this->renderer->emailHtml('admin', $body),
                    'reply_url' => MessageRenderer::braces($replyUrl),
                    'reply_note' => $replyTo === '' ? '1' : '',
                    'reply_by_email' => !$repliesOpen && $replyTo !== '' ? '1' : '',
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
                ->addTo($recipient);
            if ($replyTo !== '') {
                $builder->setReplyTo($replyTo);
            }
            $transport = $builder->getTransport();
            $this->threadHeaders->apply($transport, $this->threadHeaders->rootId((int)$submission->getId(), $storeId));
            $transport->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
