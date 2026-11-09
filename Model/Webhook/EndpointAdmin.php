<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Phrase;

/**
 * What the admin pages do with an endpoint: save (with the URL policy, the personal-data gate, audit and notices).
 * Audit details and notices carry the endpoint name and host only, never the path or query.
 */
class EndpointAdmin
{
    public const MAX_ACTIVE = 50;

    public function __construct(
        private readonly EndpointRepository $repository,
        private readonly UrlPolicy $urlPolicy,
        private readonly Audit $audit,
        private readonly NotifierInterface $notifier,
        private readonly EndpointInput $validator
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return EndpointSecret|null the new secret, for a created endpoint only
     * @throws EndpointInputException every refusal at once, keyed by field
     * @throws NoSuchEntityException
     */
    public function save(array $input, ?int $id, bool $mayChangePersonal, ?int $adminId): ?EndpointSecret
    {
        $errors = [];
        $safe = null;
        try {
            $safe = $this->urlPolicy->validateForSave((string)($input['url'] ?? ''));
            $input['url'] = $safe->toString();
        } catch (UnsafeUrlException $e) {
            $errors['url'] = $e->getMessage();
        }
        try {
            $normalized = $this->validator->normalize($input);
        } catch (EndpointInputException $e) {
            $normalized = null;
            $errors = $e->fieldErrors() + $errors;
        }
        $stored = $id === null ? null : $this->repository->getView($id);
        if ($normalized !== null) {
            $errors += $this->ruleErrors($normalized, $stored, $mayChangePersonal);
        }
        if ($errors !== [] || $safe === null || $normalized === null) {
            throw new EndpointInputException($errors);
        }
        $name = $normalized['name'];

        if ($stored === null) {
            $input['include_personal'] = $mayChangePersonal && !empty($input['include_personal']);
            $secret = $this->repository->create($input, $adminId);
            $personal = (bool)$input['include_personal'];
            if ($personal) {
                $this->audit->record($secret->endpointId(), $adminId, 'personal_on');
            }
            if ($input['debug_responses'] ?? false) {
                $this->audit->record($secret->endpointId(), $adminId, 'debug_on');
            }
            $this->notify(
                __('Webhook endpoint "%1" was created', $name),
                __('Submission data will be sent to %1.', $safe->host)
                    . ($personal ? ' ' . __('Personal data is included.') : '')
            );

            return $secret;
        }

        if (!$mayChangePersonal) {
            $input['include_personal'] = $stored->includePersonal();
        }
        $reEnable = !empty($input['is_active']) && !$stored->isActive();
        $this->repository->update($stored->id(), $input, $reEnable);
        $this->recordChanges($stored, $input['url'], $safe->host, $name, !empty($input['include_personal']), !empty($input['debug_responses']), !empty($input['is_active']), $adminId);

        return null;
    }

    /**
     * @param array<string, mixed> $new normalised input
     * @return array<string, string>
     */
    private function ruleErrors(array $new, ?EndpointView $stored, bool $mayChangePersonal): array
    {
        $errors = [];
        if ($stored !== null && !$mayChangePersonal && $stored->includePersonal()) {
            $message = (string)__('Only admins who may send personal data can change this on a personal data endpoint.');
            if ($this->receiverChanged($stored, $new)) {
                $errors['url'] = (string)__(
                    'Only admins who may send personal data can change where a personal data endpoint sends it.'
                );
            }
            if ($new['events'] !== $stored->events()) {
                $errors['events'] = $message;
            }
            if ($new['debug_responses'] && !$stored->debugResponses()) {
                $errors['debug_responses'] = $message;
            }
            if ($new['is_active'] && !$stored->isActive()) {
                $errors['is_active'] = $message;
            }
        }
        $activates = $new['is_active'] && ($stored === null || !$stored->isActive());
        if (!isset($errors['is_active']) && $activates && $this->repository->countActive() >= self::MAX_ACTIVE) {
            $errors['is_active'] = (string)__(
                'At most %1 endpoints can be active. Deactivate or delete one first.',
                self::MAX_ACTIVE
            );
        }

        return $errors;
    }

    /** @param array<string, mixed> $new normalised input */
    private function receiverChanged(EndpointView $stored, array $new): bool
    {
        return $new['url'] !== $stored->url()
            || $new['scope_type'] !== $stored->scopeType()
            || $new['scope_ids'] !== $stored->scopeIds()
            || $new['all_forms'] !== $stored->allForms()
            || $new['form_ids'] !== $stored->formIds()
            || $new['field_mode'] !== $stored->fieldMode()
            || $new['fields'] !== $stored->fields();
    }

    /** @throws NoSuchEntityException */
    public function delete(int $id, ?int $adminId): void
    {
        $this->repository->softDelete($id, $adminId);
    }

    private function recordChanges(
        EndpointView $stored,
        string $url,
        string $host,
        string $name,
        bool $personal,
        bool $debug,
        bool $active,
        ?int $adminId
    ): void {
        $id = $stored->id();
        if ($stored->url() !== $url) {
            $this->audit->record($id, $adminId, 'url_changed', $host);
            $this->notify(
                __('Webhook endpoint "%1" now points to a different URL', $name),
                __('Submission data will be sent to %1.', $host)
            );
        }
        if ($personal !== $stored->includePersonal()) {
            $this->audit->record($id, $adminId, $personal ? 'personal_on' : 'personal_off');
            $this->notify(
                $personal
                    ? __('Webhook endpoint "%1" now sends personal data', $name)
                    : __('Webhook endpoint "%1" no longer sends personal data', $name),
                __('Receiver: %1.', $host)
            );
        }
        if ($debug !== $stored->debugResponses()) {
            $this->audit->record($id, $adminId, $debug ? 'debug_on' : 'debug_off');
        }
        if ($active !== $stored->isActive()) {
            $this->audit->record($id, $adminId, $active ? 'enabled' : 'disabled');
        }
    }

    private function notify(Phrase $title, Phrase|string $description): void
    {
        $this->notifier->addMajor((string)$title, (string)$description);
    }
}
