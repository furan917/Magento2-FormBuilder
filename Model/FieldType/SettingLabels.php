<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

class SettingLabels
{
    /**
     * Translated per call so the request's admin locale applies.
     *
     * @param array<string, array<string, mixed>> $spec
     * @return array<string, array<string, mixed>>
     */
    public function apply(array $spec): array
    {
        foreach ($spec as $key => $entry) {
            if (!is_array($entry) || isset($entry['label'])) {
                continue;
            }
            $label = $this->label((string)$key);
            if ($label !== null) {
                $spec[$key]['label'] = $label;
            }
        }
        return $spec;
    }

    public function label(string $key): ?string
    {
        return match ($key) {
            'prefix' => (string)__('Prefix'),
            'suffix' => (string)__('Suffix'),
            'mask' => (string)__('Input mask'),
            'rows' => (string)__('Rows'),
            'step' => (string)__('Step'),
            'empty_label' => (string)__('Empty option label'),
            'layout' => (string)__('Layout'),
            'checked_value' => (string)__('Value when checked'),
            'email_field' => (string)__('Email field'),
            'max' => (string)__('Maximum'),
            'min' => (string)__('Minimum'),
            'extensions' => (string)__('Allowed file types'),
            'max_size_mb' => (string)__('Maximum file size (MB)'),
            'details' => (string)__('Details'),
            'privacy_url' => (string)__('Privacy policy URL'),
            'privacy_label' => (string)__('Privacy policy link text'),
            'on_value' => (string)__('Value when on'),
            'low_label' => (string)__('Low end label'),
            'high_label' => (string)__('High end label'),
            'require_all' => (string)__('Require every row'),
            'max_items' => (string)__('Maximum items'),
            'add_label' => (string)__('Add button label'),
            'min_days' => (string)__('Minimum days'),
            'max_days' => (string)__('Maximum days'),
            'min_date' => (string)__('Earliest date'),
            'max_date' => (string)__('Latest date'),
            'start_label' => (string)__('Start label'),
            'end_label' => (string)__('End label'),
            'default_lat' => (string)__('Default latitude'),
            'default_lng' => (string)__('Default longitude'),
            'default_zoom' => (string)__('Default zoom'),
            'height' => (string)__('Height (px)'),
            'source' => (string)__('Value source'),
            'query_param' => (string)__('Query parameter'),
            default => null,
        };
    }
}
