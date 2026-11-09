<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Storefront;

use Furan\FormBuilder\Model\Condition\ConditionNormalizer;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\SchemaFields;

/** Decides whether a form must fetch its token on load instead of waiting for the first interaction. */
class Eagerness
{
    public function __construct(
        private readonly Sources $prefillSources,
        private readonly Pool $fieldTypes
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schema
     */
    public function isEager(array $settings, array $schema, ?int $productId): bool
    {
        // A cached open page must learn from the token that the schedule has since closed it.
        if (($settings['open_from'] ?? '') !== '' || ($settings['close_at'] ?? '') !== '') {
            return true;
        }
        if (!empty($settings['access_login_required']) || !empty($settings['access_groups'])
            || ($settings['survey_mode'] ?? 'off') !== 'off'
        ) {
            return true;
        }
        return $this->hasPrefillNeedingToken($schema, $productId !== null) || $this->usesRelativeDate($schema);
    }

    /**
     * Relative dates need the store's today, which only the token carries (the page may be cached for days).
     *
     * @param array<string, mixed> $container
     */
    private function usesRelativeDate(array $container): bool
    {
        foreach (['condition', 'skip'] as $slot) {
            if (is_array($container[$slot] ?? null) && $this->hasRelativeDateRule($container[$slot], 1)) {
                return true;
            }
        }
        foreach ($container['pages'] ?? $container['items'] ?? [] as $child) {
            if (is_array($child) && $this->usesRelativeDate($child)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $group
     */
    private function hasRelativeDateRule(array $group, int $level): bool
    {
        $rules = $group['rules'] ?? null;
        if (!is_array($rules) || $level > ConditionNormalizer::MAX_LEVELS) {
            return false;
        }
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (ConditionNormalizer::isGroup($rule) ? $this->hasRelativeDateRule($rule, $level + 1) : isset($rule['value_date'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $schema */
    private function hasPrefillNeedingToken(array $schema, bool $hasProduct): bool
    {
        foreach (SchemaFields::all($schema) as $field) {
            $source = $this->prefillSources->get((string)($field['prefill'] ?? ''));
            if ($source === null) {
                continue;
            }
            if ($source->getGroup() !== Sources::GROUP_PRODUCT
                || ($hasProduct && !$this->fieldTypes->isServerValue((string)($field['type'] ?? '')))
            ) {
                return true;
            }
        }
        return false;
    }
}
