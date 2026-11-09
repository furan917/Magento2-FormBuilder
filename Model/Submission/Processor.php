<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Access\FormAccess;
use Furan\FormBuilder\Model\Action\ActionRunner;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Prefill\LockedValues;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Furan\FormBuilder\Model\Spam\SpamGuard;
use Furan\FormBuilder\Model\Spam\TokenService;
use Furan\FormBuilder\Model\Survey\AlreadyAnsweredException;
use Furan\FormBuilder\Model\Upload\SubmissionFiles;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Processor
{
    public function __construct(
        private readonly SpamGuard $spamGuard,
        private readonly Validator $validator,
        private readonly ManagerInterface $eventManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly TokenService $tokenService,
        private readonly Pool $fieldTypes,
        private readonly Translator $translator,
        private readonly FormAccess $formAccess,
        private readonly LockedValues $lockedValues,
        private readonly SubmissionEditor $editor,
        private readonly Impersonation $impersonation,
        private readonly ActionRunner $actionRunner,
        private readonly SubmissionFiles $files,
        private readonly SubmissionBuilder $builder,
        private readonly EditTargetResolver $editTargets,
        private readonly RateLimiter $rateLimiter,
        private readonly ScheduleGate $schedule,
        private readonly NewSubmissionWriter $writer
    ) {
    }

    public function process(FormInterface $form, SubmissionInput $input): Result
    {
        try {
            // Visitors see their store view's texts; stored answers keep the default ones.
            $display = $this->translator->localize($form, $input->storeId);
            $settings = $display->getSettings();
            if ($this->files->isTooLarge(SchemaFields::all($form->getSchema()), $input->contentLength, $input->files)) {
                return Result::refused((string)__('The files you chose are too large.'), 413);
            }
            $closed = $this->schedule->refusal($display);
            if ($closed !== null) {
                return Result::refused($closed['message'], 403);
            }
            $spam = $this->spamGuard->check($input, $form);
            $token = $input->formToken;
            if ($spam->isSilent()) {
                $this->consumeToken($token);
                return $this->success($settings, $this->freshReference(), $input->storeId);
            }
            if ($spam->isReject()) {
                return $this->spamRefusal($spam);
            }
            if (!$this->consumeToken($token)) {
                return Result::refresh();
            }
            if ($this->rateLimiter->formCeilingReached($form)) {
                $this->tokenService->release($token);
                return Result::tooMany();
            }
        } catch (\Throwable $e) {
            return $this->failed($e);
        }

        $result = $this->isAllowed($settings, $input)
            ? $this->submit($form, $display, $input, $settings)
            : Result::refused((string)__('You are not allowed to submit this form.'), 403);
        if (!$result->isSuccess() && $token !== '') {
            $this->tokenService->release($token);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function isAllowed(array $settings, SubmissionInput $input): bool
    {
        return $this->formAccess->check($settings, $input->isLoggedIn(), $input->customerGroupId) === null;
    }

    private function consumeToken(string $token): bool
    {
        return $token !== '' && $this->tokenService->consume($token);
    }

    private function spamRefusal(SpamResult $spam): Result
    {
        $message = $spam->getMessage();

        return Result::refused($message !== null ? (string)$message : (string)__('Please refresh the page and try again.'), $spam->getHttpCode());
    }

    private function failed(\Throwable $e): Result
    {
        $this->logger->error('Form submission failed: ' . $e->getMessage(), ['exception' => $e]);
        return Result::failed();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function submit(FormInterface $form, FormInterface $display, SubmissionInput $input, array $settings): Result
    {
        $storedFiles = [];
        try {
            $storeId = $input->storeId;
            $schema = $this->withMessages($form->getSchema(), $display->getSchema());
            $fields = SchemaFields::all($schema);
            $product = $this->lockedValues->product($input->productId, $storeId);
            $target = $this->editTargets->resolve($form, $settings, $input);
            if ($target instanceof Result) {
                return $target;
            }
            $editId = $target->submissionId;
            $storedFileNames = $target->storedFileNames;
            if ($input->impersonated && ($editId !== null || ($settings['survey_mode'] ?? 'off') !== 'off')) {
                return Result::refused($this->impersonation->refusal(), 403);
            }
            $posted = $this->lockedValues->apply(
                $fields,
                $input->fields,
                $input->customerId,
                $product,
                $storeId
            );
            // Consent is never given for a customer by an admin, and is never required of anyone.
            $consentNames = $this->consentNames($fields);
            if ($input->impersonated) {
                $posted = array_merge($posted, array_fill_keys($consentNames, ''));
            }
            $result = $this->validator->validate(
                $schema,
                $posted,
                $input->files,
                $storeId,
                array_values(array_unique(array_merge(
                    $storedFileNames,
                    $consentNames,
                    $this->lockedValues->serverOwned($fields, $input->customerId)
                )))
            );
            if (!$result->isValid()) {
                return Result::invalid($result->getErrors());
            }
            $observed = $this->validateAfter($form, $result->getValues(), $fields);
            if ($observed !== null) {
                return $observed;
            }

            $spam = $this->spamGuard->checkValues($form, $result->getValues(), $input);
            if (!$spam->isPass()) {
                $this->logger->info('Form Builder spam check ' . $spam->getCode() . ' stopped a submission.');
                return $spam->isSilent()
                    ? $this->success($settings, $this->freshReference(), $storeId)
                    : $this->spamRefusal($spam);
            }

            $persist = (bool)($settings['store_submissions'] ?? true);
            $fileErrors = [];
            $fileMeta = $this->files->process(
                $result->getFiles(),
                $fields,
                (int)$form->getId(),
                $persist,
                $storedFiles,
                $fileErrors
            );
            if ($fileErrors !== []) {
                $this->files->delete($storedFiles);
                return Result::invalid($fileErrors);
            }

            $values = $result->getValues();
            $payload = $this->builder->payload($fields, $values, $fileMeta, $result->getUnansweredFiles());
            if ($editId !== null) {
                $refusal = $this->editor->apply($form, $target, $fields, $values, $payload, $storedFiles, $input->customerId);
                if ($refusal !== null) {
                    return $refusal;
                }
                return $this->success($settings, $this->editor->complete($display, $editId, $values), $storeId);
            }
            $submission = $this->builder->build($form, $settings, $fields, $values, $payload, $input)
                ->setContext($this->lockedValues->snapshot($product));
            if ($persist) {
                try {
                    $this->writer->write($form, $submission, $settings, $fields, $values, $input, $storedFiles);
                } catch (AlreadyAnsweredException) {
                    $this->files->delete($storedFiles);
                    // Guests are keyed by an unverified email: answer exactly as for a new respondent, so the
                    // form cannot be used to learn which addresses have answered.
                    return $input->isLoggedIn()
                        ? Result::refused((string)__('You have already answered this form.'), 400)
                        : $this->success($settings, $submission->getReference(), $storeId);
                }
                // The saved submission now owns its files, so a later failure must not delete them.
                $storedFiles = [];
            }

            $this->dispatchSubmissionAfter($form, $submission, $values);
            $this->actionRunner->run($display, $submission, $values);

            return $this->success($settings, $submission->getReference(), $storeId);
        } catch (\Throwable $e) {
            $this->files->delete($storedFiles);
            return $this->failed($e);
        }
    }

    /**
     * An observer failure is logged: the submission is already stored and the actions still have to run.
     *
     * @param array<string, mixed> $values
     */
    private function dispatchSubmissionAfter(FormInterface $form, SubmissionInterface $submission, array $values): void
    {
        try {
            $this->eventManager->dispatch(
                'furan_formbuilder_submission_after',
                [
                    'form' => $form,
                    'submission' => $submission,
                    'submission_id' => (int)$submission->getId() > 0 ? (int)$submission->getId() : null,
                    'values' => $values,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Submission after observer failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Observers get a read-only snapshot of the values and add errors (name => message) to block the submit.
     * An error for a name that is not a schema field becomes the general message.
     *
     * @param array<string, mixed> $values
     * @param list<array<string, mixed>> $fields
     */
    private function validateAfter(FormInterface $form, array $values, array $fields): ?Result
    {
        $errors = new DataObject();
        $this->eventManager->dispatch(
            'furan_formbuilder_submission_validate_after',
            ['form' => $form, 'values' => new DataObject($values), 'errors' => $errors]
        );
        $added = array_map('strval', $errors->getData());
        if ($added === []) {
            return null;
        }
        $names = array_column($fields, 'name');
        $general = array_values(array_diff_key($added, array_flip($names)))[0] ?? null;

        return Result::invalid($added, $general);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return string[]
     */
    private function consentNames(array $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            $code = (string)($field['type'] ?? '');
            if ($this->fieldTypes->isConsent($code)) {
                $names[] = (string)$field['name'];
            }
        }
        return $names;
    }

    /**
     * The default schema with the store view's rule messages, so 422 errors match what the browser shows.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $display
     * @return array<string, mixed>
     */
    private function withMessages(array $schema, array $display): array
    {
        if ($schema === $display) {
            return $schema;
        }
        $messages = [];
        foreach (SchemaFields::all($display) as $field) {
            $messages[$field['id'] ?? ''] = array_column($field['rules'] ?? [], 'message');
        }
        return SchemaFields::map($schema, static function (array $field) use ($messages): array {
            foreach ($field['rules'] ?? [] as $r => $rule) {
                $field['rules'][$r]['message'] = $messages[$field['id'] ?? ''][$r] ?? ($rule['message'] ?? '');
            }
            return $field;
        });
    }

    /** Same shape for every success, so a silent spam drop or a duplicate guest answer cannot be told apart. */
    private function freshReference(): string
    {
        return Reference::toString(Reference::generate());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function success(array $settings, ?string $reference, int $storeId): Result
    {
        $message = (string)($settings['success_message'] ?? '');
        $redirect = (string)($settings['success_redirect'] ?? '');
        $message = $message !== '' ? $message : (string)__('Thank you, your form has been sent.');

        return Result::success(
            Reference::fill($message, (string)$reference),
            $redirect === '' ? null : $this->absoluteUrl($redirect, $storeId),
            $reference
        );
    }

    private function absoluteUrl(string $url, int $storeId): string
    {
        if (str_starts_with($url, '/')) {
            $store = $this->storeManager->getStore($storeId);

            return rtrim($store instanceof Store ? (string)$store->getBaseUrl() : '', '/') . $url;
        }
        return $url;
    }
}
