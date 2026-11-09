<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** Validates and normalises the admin-supplied endpoint settings. The URL is checked by the URL policy, not here. */
class EndpointInput
{
    public const EVENTS = ['submission.created', 'submission.updated', 'submission.status_changed'];
    private const SCOPES = ['website', 'store'];
    private const FIELD_MODES = ['all', 'selected'];
    private const MAX_IDS = 1000;
    private const MAX_FIELDS = 200;

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     * @throws EndpointInputException
     */
    public function normalize(array $raw): array
    {
        $name = trim((string)($raw['name'] ?? ''));
        $url = trim((string)($raw['url'] ?? ''));
        $scopeType = (string)($raw['scope_type'] ?? '');
        $fieldMode = (string)($raw['field_mode'] ?? 'all');
        $allForms = !empty($raw['all_forms']);
        $scopeIds = $this->ids($raw['scope_ids'] ?? []);
        $formIds = $this->ids($raw['form_ids'] ?? []);
        $events = $this->events($raw['events'] ?? []);
        $fields = $this->fields($raw['fields'] ?? []);

        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = (string)__('The name is required and can have at most 120 characters.');
        }
        if ($url === '' || strlen($url) > 2048) {
            $errors['url'] = (string)__('The URL is required and can have at most 2048 characters.');
        }
        if (!in_array($scopeType, self::SCOPES, true) || $scopeIds === []) {
            $errors['scope'] = (string)__('Choose at least one website or store view.');
        }
        if (!$allForms && $formIds === []) {
            $errors['forms'] = (string)__('Choose at least one form, or choose all forms.');
        }
        if ($events === []) {
            $errors['events'] = (string)__('Choose at least one event.');
        }
        if (!in_array($fieldMode, self::FIELD_MODES, true) || ($fieldMode === 'selected' && $fields === [])) {
            $errors['fields'] = (string)__('Choose at least one field, or send all fields.');
        }
        if ($errors !== []) {
            throw new EndpointInputException($errors);
        }

        return [
            'name' => $name,
            'url' => $url,
            'is_active' => !empty($raw['is_active']),
            'scope_type' => $scopeType,
            'scope_ids' => $scopeIds,
            'all_forms' => $allForms,
            'form_ids' => $allForms ? [] : $formIds,
            'events' => $events,
            'field_mode' => $fieldMode,
            'fields' => $fieldMode === 'selected' ? $fields : [],
            'include_personal' => !empty($raw['include_personal']),
            'debug_responses' => !empty($raw['debug_responses']),
        ];
    }

    /** @return int[] */
    private function ids(mixed $raw): array
    {
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0) {
                $ids[(int)$id] = (int)$id;
            }
        }
        sort($ids);

        return array_slice($ids, 0, self::MAX_IDS);
    }

    /** @return string[] */
    private function events(mixed $raw): array
    {
        $events = array_filter(is_array($raw) ? $raw : [], is_string(...));

        return array_values(array_intersect(self::EVENTS, $events));
    }

    /** @return string[] */
    private function fields(mixed $raw): array
    {
        $fields = [];
        foreach (is_array($raw) ? $raw : [] as $name) {
            if (is_string($name) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) {
                $fields[$name] = $name;
            }
        }

        return array_slice(array_values($fields), 0, self::MAX_FIELDS);
    }
}
