<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Model\Email\SafeAddress;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Email\EmailConditions;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Email\NotificationAction;
use Furan\FormBuilder\Model\Mail\MailQueue;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Translate\Inline\StateInterface;
use Psr\Log\LoggerInterface;

/** Mails the form's notification recipients about a customer reply; failures are logged, never thrown. */
class CustomerReplyNotifier
{
    public function __construct(
        private readonly TransportBuilderFactory $transportBuilderFactory,
        private readonly StateInterface $inlineTranslation,
        private readonly Config $config,
        private readonly NotificationAction $recipients,
        private readonly MessageRenderer $renderer,
        private readonly ThreadHeaders $threadHeaders,
        private readonly LoggerInterface $logger,
        private readonly StoreScope $storeScope,
        private readonly Translator $translator,
        private readonly EmailConditions $conditions,
        private readonly SubmissionStore $submissionStore,
        private readonly MailQueue $mailQueue,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function notify(
        FormInterface $form,
        SubmissionInterface $submission,
        int $messageId,
        string $customerEmail,
        string $body
    ): void {
        if (empty($form->getSettings()['notify_enabled']) || $this->mailQueue->publishReply($messageId)) {
            return;
        }
        $this->send($form, $submission, $customerEmail, $body);
    }

    /** Sends now: from notify() when the reply is not queued, and from the mail queue consumer. */
    public function send(FormInterface $form, SubmissionInterface $submission, string $customerEmail, string $body): void
    {
        $settings = $form->getSettings();
        if (empty($settings['notify_enabled'])) {
            return;
        }
        $storeId = $this->submissionStore->resolve($submission, $form);
        $context = $this->conditions->context($form, $submission, $storeId);
        $to = $this->recipients->resolveRecipients($settings, $context['values'], $context['today'], $context['fields']);
        if ($to === []) {
            return;
        }

        // The customer may be browsing another store; the mail belongs to the submission's store and language.
        $this->storeScope->run($storeId, fn () => $this->compose($form, $submission, $customerEmail, $body, $to, $storeId));
    }

    /** @param string[] $to */
    private function compose(
        FormInterface $form,
        SubmissionInterface $submission,
        string $customerEmail,
        string $body,
        array $to,
        int $storeId
    ): void {
        $title = trim((string)preg_replace('/[\r\n]+/', ' ', $this->translator->localize($form, $storeId)->getTitle()));

        $this->inlineTranslation->suspend();
        try {
            $builder = $this->transportBuilderFactory->create();
            $builder->setTemplateIdentifier($this->config->getEmailTemplate('customer_reply', $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'subject' => MessageRenderer::braces((string)__('Customer reply: %1', $title)),
                    'body_html' => $this->renderer->emailHtml('customer', $body),
                    'submission_id' => (int)$submission->getId(),
                    'reference' => MessageRenderer::braces($submission->getReference()),
                    // Admin secret keys belong to a session that is gone when the mail is read.
                    'admin_url' => MessageRenderer::braces($this->backendUrl->getUrl(
                        'furanform/submission/open',
                        ['id' => (int)$submission->getId(), '_nosecret' => true]
                    )),
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId);
            foreach ($to as $address) {
                $builder->addTo((string)$address);
            }
            if (SafeAddress::isValid($customerEmail)) {
                $builder->setReplyTo($customerEmail);
            }
            $transport = $builder->getTransport();
            $this->threadHeaders->apply($transport, $this->threadHeaders->rootId((int)$submission->getId(), $storeId));
            $transport->sendMessage();
        } catch (\Throwable $e) {
            // Class only: transport messages can contain addresses.
            $this->logger->error('Furan_FormBuilder: customer reply notification failed: ' . $e::class);
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
