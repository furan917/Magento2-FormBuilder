<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Access\FormAccess;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Submission\EditToken;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Customer\Model\Session;
use Magento\Framework\UrlInterface;

/**
 * Per-visitor part of the token response. Identity comes from the customer session only.
 */
class TokenPayload
{
    public const REASON_ANSWERED = 'answered';

    public function __construct(
        private readonly Session $customerSession,
        private readonly FormAccess $formAccess,
        private readonly PrefillService $prefillService,
        private readonly Respondents $respondents,
        private readonly Sources $sources,
        private readonly PreviousAnswers $previousAnswers,
        private readonly EditToken $editToken,
        private readonly UrlInterface $url,
        private readonly LockedValues $lockedValues
    ) {
    }

    /**
     * Only the access verdict, for answers that must carry no per-visitor data (closed form).
     *
     * @return array{access: array{allowed: bool, reason: ?string, loginUrl: string}}
     */
    public function access(FormInterface $form): array
    {
        $loggedIn = $this->customerSession->isLoggedIn();
        $reason = $this->formAccess->check($form->getSettings(), $loggedIn, (int)$this->customerSession->getCustomerGroupId());

        return ['access' => $this->accessBlock($reason)];
    }

    /**
     * @return array{customer: bool, access: array<string, mixed>, prefill: array<string, mixed>, locked: string[], product: ?array<string, mixed>, previous: array<string, mixed>, previousAt?: int, edit?: string}
     */
    public function build(FormInterface $form, int $storeId, int $productId, string $editToken = ''): array
    {
        $settings = $form->getSettings();
        $loggedIn = $this->customerSession->isLoggedIn();
        $customerId = $loggedIn ? (int)$this->customerSession->getCustomerId() : 0;
        $reason = $this->formAccess->check($settings, $loggedIn, (int)$this->customerSession->getCustomerGroupId());
        $previous = null;
        $edit = null;
        if ($reason === null && $loggedIn && $editToken !== '') {
            $edit = $this->editState($form, $settings, $customerId, $editToken);
        }
        if ($edit !== null) {
            $previous = $edit;
        } elseif ($reason === null && $loggedIn) {
            $previous = $this->surveyState($form, $settings, $customerId);
            if ($previous === false) {
                $reason = self::REASON_ANSWERED;
                $previous = null;
            }
        }

        $result = [
            'customer' => $loggedIn,
            'access' => $this->accessBlock($reason),
            'prefill' => [],
            'locked' => [],
            'product' => null,
            'previous' => [],
        ];
        if ($reason !== null) {
            return $result;
        }

        $fields = $this->prefilledFields($form->getSchema());
        if ($previous !== null) {
            // The browser drops a local draft saved before this answer.
            $result['previous'] = $previous['answers'];
            $result['previousAt'] = $previous['savedAt'];
        }
        if ($edit !== null) {
            $result['edit'] = $editToken;
        }
        if ($loggedIn) {
            [$result['prefill'], $result['locked']] = $this->customerPrefill($fields, $customerId, $storeId);
        }
        if ($productId > 0 && array_filter($fields, fn(array $f): bool => $this->sources->isProduct($f['prefill']))) {
            $result['product'] = $this->prefillService->product($productId, $storeId);
        }

        return $result;
    }

    /** @return array{allowed: bool, reason: ?string, loginUrl: string} */
    private function accessBlock(?string $reason): array
    {
        return [
            'allowed' => $reason === null,
            'reason' => $reason,
            'loginUrl' => $this->url->getUrl('furanform/account/login', ['_scope_to_url' => true]),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{answers: array<string, mixed>, savedAt: int}|null the submission the token names; null when the token is not accepted
     */
    private function editState(FormInterface $form, array $settings, int $customerId, string $editToken): ?array
    {
        $formId = (int)$form->getId();
        $submissionId = empty($settings['account_edit']) ? null : $this->editToken->verify($editToken, $customerId, $formId);

        return $submissionId === null
            ? null
            : $this->previousAnswers->load($submissionId, $formId, $customerId, $form->getSchema());
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{answers: array<string, mixed>, savedAt: int}|false|null the answer to refill, null when there is
     *     none, false when the customer is done with the form
     */
    private function surveyState(FormInterface $form, array $settings, int $customerId): array|false|null
    {
        $mode = $settings['survey_mode'] ?? 'off';
        if ($mode !== 'once' && $mode !== 'edit') {
            return null;
        }
        $formId = (int)$form->getId();
        $respondent = $this->respondents->find($formId, [$this->respondents->customerKey($customerId)]);
        if ($respondent === null) {
            return null;
        }
        if ($mode === 'once' || $respondent['submission_id'] === null) {
            return false;
        }
        return $this->previousAnswers->load($respondent['submission_id'], $formId, $customerId, $form->getSchema()) ?? false;
    }

    /**
     * @param array<string, array{prefill: string, owned: bool, type: string}> $fields
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private function customerPrefill(array $fields, int $customerId, int $storeId): array
    {
        $personal = array_filter(
            $fields,
            fn(array $f): bool => $this->sources->suits($f['prefill'], $f['type']) && !$this->sources->isProduct($f['prefill'])
        );
        if ($personal === []) {
            return [[], []];
        }
        $values = $this->prefillService->valuesFor(
            $customerId,
            array_values(array_unique(array_column($personal, 'prefill'))),
            $storeId
        );

        $prefill = [];
        $locked = [];
        foreach ($personal as $name => $field) {
            $value = $values[$field['prefill']] ?? '';
            if ($value !== '' && $value !== []) {
                $prefill[$name] = $value;
            }
            // Locked even when empty: the server stores the account's value, blank included.
            if ($field['owned']) {
                $locked[] = $name;
            }
        }
        return [$prefill, $locked];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array{prefill: string, owned: bool, type: string}> field name => prefill spec
     */
    private function prefilledFields(array $schema): array
    {
        $fields = [];
        foreach (SchemaFields::all($schema) as $item) {
            if (($item['prefill'] ?? '') !== '' && isset($item['name'])) {
                $fields[(string)$item['name']] = [
                    'prefill' => (string)$item['prefill'],
                    'owned' => $this->lockedValues->ownsPrefill($item),
                    'type' => (string)($item['type'] ?? ''),
                ];
            }
        }
        return $fields;
    }
}
