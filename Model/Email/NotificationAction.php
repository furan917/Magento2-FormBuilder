<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Api\RetryableActionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Submission\StoreDisplayContext;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Translate\Inline\StateInterface;
use Psr\Log\LoggerInterface;

class NotificationAction implements DeferrableActionInterface, RetryableActionInterface
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TransportBuilderFactory $transportBuilderFactory,
        private readonly StateInterface $inlineTranslation,
        private readonly SubmissionStore $submissionStore,
        private readonly Config $config,
        private readonly ValuesTable $valuesTable,
        private readonly SubmissionVars $submissionVars,
        private readonly EmailConditions $conditions,
        private readonly Pool $fieldTypes,
        private readonly LoggerInterface $logger,
        private readonly SubmissionMailer $mailer,
        private readonly StoreDisplayContext $displayContexts
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
        return !empty($form->getSettings()['notify_enabled']);
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
        if (empty($settings['notify_enabled'])) {
            return;
        }
        $storeId = $this->submissionStore->resolve($submission, $form);
        $context = $this->conditions->context($form, $submission, $storeId);
        if (!$this->conditions->allows($settings['notify_when'] ?? null, $context)) {
            $this->logger->debug('Form Builder: notification skipped by its send when condition', [
                'form_id' => $submission->getFormId(),
            ]);
            return;
        }
        $recipients = $this->resolveRecipients($settings, $context['values'], $context['today'], $context['fields']);
        if ($recipients === []) {
            return;
        }

        $subject = trim((string)($settings['notify_subject'] ?? ''));
        $subject = $subject !== '' ? $subject : (string)__('New submission: %1', $form->getTitle());
        $subject = trim((string)preg_replace('/[\r\n]+/', ' ', $subject));

        // The store view's locale, like the template and subject.
        $display = $this->displayContexts->forStore($storeId);
        $this->inlineTranslation->suspend();
        try {
            $builder = $this->transportBuilderFactory->create();
            $builder->setTemplateIdentifier($this->config->getEmailTemplate('notification', $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'form_title' => MessageRenderer::braces($form->getTitle()),
                    'values_html' => $this->valuesTable->toHtml($submission->getPayload(), $form->getSchema(), $display),
                    'product_html' => $this->valuesTable->contextHtml($submission->getContext()),
                    'submission_id' => $submission->getId(),
                    'submission' => $this->submissionVars->toArray($submission),
                    'values' => $this->valuesTable->toList($submission->getPayload(), $form->getSchema(), $display),
                    'subject' => MessageRenderer::braces($subject),
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId);
            foreach ($recipients as $address) {
                $builder->addTo((string)$address);
            }
            foreach ((array)($settings['notify_cc'] ?? []) as $address) {
                $builder->addCc((string)$address);
            }
            foreach ((array)($settings['notify_bcc'] ?? []) as $address) {
                $builder->addBcc((string)$address);
            }
            $replyTo = $this->resolveReplyTo($form, $values);
            if ($replyTo !== null) {
                $builder->setReplyTo($replyTo);
            }
            $this->mailer->send($builder->getTransport(), $submission, $storeId);
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    /**
     * Default recipients plus matching routes (a matching 'replace' route drops the defaults).
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $values
     * @param string|null $today Y-m-d for relative date rules (null = they never match)
     * @param array<string, array<string, mixed>> $fieldsByName
     * @return string[]
     */
    public function resolveRecipients(array $settings, array $values, ?string $today = null, array $fieldsByName = []): array
    {
        $context = ['values' => $values, 'fields' => $fieldsByName, 'today' => (string)$today];
        $defaults = (array)($settings['notify_recipients'] ?? []);
        $routed = [];
        $replacing = false;
        foreach ((array)($settings['notify_routes'] ?? []) as $route) {
            if (!is_array($route) || !$this->conditions->matches($route['condition'] ?? null, $context)) {
                continue;
            }
            $replacing = $replacing || ($route['mode'] ?? 'add') === 'replace';
            $routed = [...$routed, ...(array)($route['emails'] ?? [])];
        }
        $all = $replacing ? $routed : array_merge($defaults, $routed);

        $unique = [];
        foreach ($all as $address) {
            $address = trim((string)$address);
            if ($address !== '') {
                $unique[strtolower($address)] ??= $address;
            }
        }

        return array_values($unique);
    }

    /**
     * @param array<string, string|array<int|string, mixed>> $values
     */
    private function resolveReplyTo(FormInterface $form, array $values): ?string
    {
        $preferred = (string)($form->getSettings()['autoresponder_email_field'] ?? '');
        $emailFields = $this->emailFieldNames($form->getSchema());
        $name = in_array($preferred, $emailFields, true) ? $preferred : ($emailFields[0] ?? null);
        $value = $name !== null ? $values[$name] ?? null : null;

        return SafeAddress::isValid($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return string[]
     */
    private function emailFieldNames(array $schema): array
    {
        $names = [];
        foreach (SchemaFields::all($schema) as $field) {
            if ($this->fieldTypes->isEmailAddress((string)($field['type'] ?? ''))) {
                $names[] = (string)$field['name'];
            }
        }

        return $names;
    }
}
