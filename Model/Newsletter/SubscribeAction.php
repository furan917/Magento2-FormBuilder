<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Newsletter;

use Furan\FormBuilder\Model\Email\SafeAddress;
use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Model\Email\QuotaAddress;
use Furan\FormBuilder\Model\FieldType\Newsletter;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Settings\FormSettings;
use Furan\FormBuilder\Model\Throttle\Counter;
use Psr\Log\LoggerInterface;

/**
 * Subscribes after a submission when the newsletter box was ticked. Everything is read for the submission's own
 * store. Consent under Login as Customer is guaranteed by Processor blanking the answer, so a queued run (which
 * has no customer session) sees the same blank value. Not retryable: the subscription manager may send mail.
 */
class SubscribeAction implements DeferrableActionInterface
{
    private const DAY = 86400;

    public function __construct(
        private readonly NewsletterBridge $bridge,
        private readonly Pool $fieldTypes,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isApplicable(FormInterface $form): bool
    {
        return $this->bridge->isAvailable() && $this->newsletterFields($form, null) !== [];
    }

    public function supports(ActionContext $context): bool
    {
        return !$context->isEdit;
    }

    /** @param array<string, string|array<int, string>> $values */
    public function execute(
        FormInterface $form,
        SubmissionInterface $submission,
        array $values,
        ActionContext $context
    ): void {
        $storeId = $submission->getStoreId();
        if ($storeId === null || !$this->bridge->isAvailable($storeId)) {
            return;
        }
        foreach ($this->newsletterFields($form, $storeId) as $field) {
            if (($values[$field['name']] ?? null) !== Newsletter::CHECKED_VALUE) {
                continue;
            }
            try {
                $this->subscribe($form, $field, $submission, $values, $storeId);
            } catch (\Throwable $e) {
                $this->logger->warning('Form Builder: newsletter subscription failed', [
                    'exception_class' => $e::class,
                ]);
            }
            return;
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string|array<int, string>> $values
     */
    private function subscribe(
        FormInterface $form,
        array $field,
        SubmissionInterface $submission,
        array $values,
        int $storeId
    ): void {
        $customerId = (int)$submission->getCustomerId();
        if ($customerId > 0) {
            $this->bridge->subscribeCustomer($customerId, $storeId);
            return;
        }
        if (!$this->bridge->allowsGuest($storeId)) {
            return;
        }
        $settings = $form->getSettings();
        $source = (string)($field['settings']['email_field'] ?? '')
            ?: FormSettings::submitterEmailField($settings);
        $email = $source === '' ? null : ($values[$source] ?? null);
        if (!SafeAddress::isValid($email)) {
            return;
        }
        // Counted before subscribing: a refused or failed attempt still uses the day's allowance.
        $key = 'ffb_nl_' . $storeId . '_' . hash('sha256', QuotaAddress::of($email));
        if ($this->counter->hit($key, self::DAY) > 1) {
            return;
        }
        $this->bridge->subscribeGuest($email, $storeId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function newsletterFields(FormInterface $form, ?int $storeId): array
    {
        $fields = [];
        foreach (SchemaFields::all($form->getSchema()) as $item) {
            $code = (string)($item['type'] ?? '');
            if ($this->fieldTypes->isUsable($code, $storeId)
                && $this->fieldTypes->get($code) instanceof Newsletter && isset($item['name'])
            ) {
                $fields[] = $item;
            }
        }

        return $fields;
    }
}
