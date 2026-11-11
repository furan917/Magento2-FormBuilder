<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\FixedValueTypeInterface;
use Furan\FormBuilder\Api\FieldType\ServerValueTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;

class Hidden extends AbstractFieldType implements StatsAwareTypeInterface, ServerValueTypeInterface, FixedValueTypeInterface
{
    private const SOURCE_FIXED = 'static';
    private const SOURCE_CODE = '/^[a-z][a-z0-9_.-]{0,63}$/';
    private const BUILT_IN_SOURCES = [self::SOURCE_FIXED, 'query', 'page_url', 'referrer'];

    private const MAX_LABEL = 100;

    /** @var string[] */
    private array $sources = [];
    /** @var array<string, string> untranslated labels of the extra sources that have one */
    private array $labels = [];

    /**
     * Each extra source is a code or `['code' => ..., 'label' => ...]`. A code that is invalid or built in, or a
     * label that is present but blank, over 100 characters or not plain text, makes the entry skipped.
     *
     * @param array<int|string, string|array{code?: mixed, label?: mixed}> $sources resolved in the browser by registry.hiddenSource()
     */
    public function __construct(array $sources = [])
    {
        $this->sources = self::BUILT_IN_SOURCES;
        foreach ($sources as $source) {
            $code = is_array($source) ? ($source['code'] ?? null) : $source;
            $label = is_array($source) && array_key_exists('label', $source) ? $this->cleanLabel($source['label']) : null;
            if (
                !is_string($code) || preg_match(self::SOURCE_CODE, $code) !== 1 || in_array($code, $this->sources, true)
                || (is_array($source) && array_key_exists('label', $source) && $label === null)
            ) {
                continue;
            }
            $this->sources[] = $code;
            if ($label !== null) {
                $this->labels[$code] = $label;
            }
        }
    }

    public function getCode(): string
    {
        return 'hidden';
    }

    public function getLabel(): string
    {
        return (string)__('Hidden field');
    }

    protected const MAX_LENGTH = 255;

    public function getSettingsSpec(): array
    {
        return [
            'source' => [
                'type' => 'enum',
                'default' => self::SOURCE_FIXED,
                'values' => $this->sources,
                'value_labels' => $this->valueLabels(),
                'hint' => (string)__(
                    "Fixed value saves the Default value. The other sources come from the visitor's browser, which can change them."
                ),
            ],
            'query_param' => ['type' => 'string', 'default' => '', 'visible_when' => ['source' => ['query']]],
        ];
    }

    /** A prefill source fills the field instead, under the prefill rules. */
    public function fixedValue(array $field): ?string
    {
        $source = is_array($field['settings'] ?? null) ? ($field['settings']['source'] ?? '') : '';
        if (($field['prefill'] ?? '') !== '' || !in_array($source, ['', self::SOURCE_FIXED], true)) {
            return null;
        }
        return is_scalar($field['default'] ?? null) ? (string)$field['default'] : '';
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_NONE;
    }

    /** @return array<string, string> translated here, per call, so the request locale applies */
    private function valueLabels(): array
    {
        $labels = [
            'static' => (string)__('Fixed value'),
            'query' => (string)__('Query parameter'),
            'page_url' => (string)__('Page URL'),
            'referrer' => (string)__('Referrer'),
        ];
        foreach ($this->labels as $code => $label) {
            $labels[$code] = (string)__($label);
        }
        return $labels;
    }

    private function cleanLabel(mixed $label): ?string
    {
        $label = is_string($label) ? trim($label) : '';
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL || preg_match('/[\x00-\x1f\x7f]/', $label) === 1) {
            return null;
        }
        return $label;
    }
}
