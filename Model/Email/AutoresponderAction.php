<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Api\RetryableActionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Submission\Reference;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Model\Throttle\Counter;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Translate\Inline\StateInterface;
use Psr\Log\LoggerInterface;

class AutoresponderAction implements DeferrableActionInterface, RetryableActionInterface
{
    // Anyone can type someone else's address into a form, so every limit errs on protecting the store's
    // sending reputation: one confirmation per address per form per day, three per address across forms.
    private const MAX_PER_ADDRESS_FORM_DAY = 1;
    private const MAX_PER_ADDRESS_DAY = 3;
    private const MAX_PER_FORM_HOUR = 50;
    private const HOUR = 3600;
    private const DAY = 86400;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TransportBuilderFactory $transportBuilderFactory,
        private readonly StateInterface $inlineTranslation,
        private readonly SubmissionStore $submissionStore,
        private readonly Config $config,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
        private readonly Escaper $escaper,
        private readonly NotifierInterface $notifier,
        private readonly SubmissionVars $submissionVars,
        private readonly EmailConditions $conditions,
        private readonly SubmissionMailer $mailer
    ) {
    }

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    public function isRetryable(\Throwable $failure): bool
    {
        return $failure instanceof MailNotSentException;
    }

    public function isApplicable(FormInterface $form): bool
    {
        return !empty($form->getSettings()['autoresponder_enabled']);
    }

    public function supports(ActionContext $context): bool
    {
        return !$context->isEdit;
    }

    public function execute(
        FormInterface $form,
        SubmissionInterface $submission,
        array $values,
        ActionContext $context
    ): void
    {
        $settings = $form->getSettings();
        if (empty($settings['autoresponder_enabled'])) {
            return;
        }
        $storeId = $this->submissionStore->resolve($submission, $form);
        // Before the quota: a skipped email must not use up the address's allowance.
        if (!$this->conditions->allows(
            $settings['autoresponder_when'] ?? null,
            $this->conditions->context($form, $submission, $storeId)
        )) {
            $this->logger->debug('Form Builder: autoresponder skipped by its send when condition', [
                'form_id' => $submission->getFormId(),
            ]);
            return;
        }
        $recipient = $values[(string)($settings['autoresponder_email_field'] ?? '')] ?? null;
        if (!SafeAddress::isValid($recipient)) {
            return;
        }
        $this->inlineTranslation->suspend();
        try {
            $reference = $submission->getReference();
            $transport = $this->transportBuilderFactory->create()
                ->setTemplateIdentifier($this->config->getEmailTemplate('autoresponder', $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'subject' => MessageRenderer::braces(
                        Reference::fill((string)($settings['autoresponder_subject'] ?? ''), $reference)
                    ),
                    'body_html' => $this->bodyHtml(
                        Reference::fill((string)($settings['autoresponder_body'] ?? ''), $reference)
                    ),
                    'form_title' => MessageRenderer::braces($form->getTitle()),
                    'submission' => $this->submissionVars->toArray($submission),
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
                ->addTo($recipient)
                ->getTransport();
            // Counted once the message is built, so a broken template or sender costs no quota.
            if (!$this->chargeQuota($recipient, $submission, $storeId, $context->attempt)) {
                $this->logger->info('Form Builder: autoresponder throttled for a recipient', [
                    'form_id' => $submission->getFormId(),
                ]);
                return;
            }
            $this->mailer->send($transport, $submission, $storeId);
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    private function bodyHtml(string $body): string
    {
        $escaped = MessageRenderer::braces($this->escaper->escapeHtml($body));

        return str_replace(["\r\n", "\r", "\n"], '<br />', $escaped);
    }

    /**
     * Wider limits are counted first, so a refusal there never uses up a narrower quota. Counting a refused
     * attempt is deliberate: an abuser cannot reset a window by retrying.
     */
    private function consumeQuota(string $address, int $formId, int $storeId): bool
    {
        $daily = $this->config->getAutoresponderDailyLimit($storeId);
        if ($daily > 0) {
            $sent = $this->counter->hit('ffb_ar_store_' . $storeId, self::DAY);
            if ($sent === $daily + 1) {
                $this->notifier->addMajor(
                    (string)__('Form Builder stopped sending confirmation emails for today'),
                    (string)__(
                        'The daily limit of %1 confirmation emails was reached for store view %2. If this is unexpected, someone may be submitting forms with other people\'s addresses: turn on reCAPTCHA for forms, or raise the limit under Stores > Configuration > Furan > Form Builder > Email.',
                        $daily,
                        $storeId
                    )
                );
            }
            if ($sent > $daily) {
                return false;
            }
        }
        if ($this->counter->hit('ffb_ar_form_' . $formId, self::HOUR) > self::MAX_PER_FORM_HOUR) {
            return false;
        }
        return $this->consumeAddressQuota($address, $formId);
    }

    private function consumeAddressQuota(string $address, int $formId): bool
    {
        $perForm = $this->counter->hit('ffb_ar_' . hash('sha256', $address . '|' . $formId), self::DAY);
        if ($perForm > self::MAX_PER_ADDRESS_FORM_DAY) {
            return false;
        }
        return $this->counter->hit('ffb_ar_' . hash('sha256', $address), self::DAY) <= self::MAX_PER_ADDRESS_DAY;
    }

    /**
     * A retry is free only for an address an earlier attempt of this submission paid for: the answers can be
     * edited between attempts. Retries of one submission run one at a time, so peek then hit cannot race.
     */
    private function chargeQuota(string $email, SubmissionInterface $submission, int $storeId, int $attempt): bool
    {
        $address = QuotaAddress::of($email);
        $paid = 'ffb_ar_paid_' . (int)$submission->getId() . '_' . hash('sha256', $address);
        if ($attempt > 1 && $this->counter->peek($paid) > 0) {
            return true;
        }
        $allowed = $attempt > 1
            ? $this->consumeAddressQuota($address, $submission->getFormId())
            : $this->consumeQuota($address, $submission->getFormId(), $storeId);
        if ($allowed) {
            $this->counter->hit($paid, self::DAY);
        }
        return $allowed;
    }
}
