<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block;

use Magento\Framework\Escaper;

/** Template variables shared by every storefront field template and partial. */
class ItemContext
{
    private const WIDTHS = ['full', 'two_thirds', 'half', 'third'];

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function build(array $item, int $formId, int $instance, Escaper $escaper): array
    {
        $name = (string)($item['name'] ?? '');
        $type = (string)($item['type'] ?? '');
        $settings = (array)($item['settings'] ?? []);
        $required = !empty($item['required']);
        $help = trim((string)($item['help'] ?? ''));
        $controlId = 'ffb-' . $formId . ($instance > 1 ? '-' . $instance : '') . '-' . $name;
        $helpId = $controlId . '-help';
        $errorId = $controlId . '-error';
        $describedBy = ($help !== '' ? $helpId . ' ' : '') . $errorId;
        $width = in_array($item['width'] ?? '', self::WIDTHS, true) ? $item['width'] : 'full';
        $layout = ($item['render']['layout'] ?? null) === 'inline' || ($settings['layout'] ?? 'stacked') === 'inline' ? 'inline' : 'stacked';
        $labelHtml = $escaper->escapeHtml((string)($item['label'] ?? ''))
            . ($required ? '<span class="ffb-req" aria-hidden="true">*</span>' : '');
        $ruleValue = static function (string $code) use ($item) {
            foreach ((array)($item['rules'] ?? []) as $rule) {
                if (($rule['rule'] ?? '') === $code) {
                    return $rule['value'] ?? null;
                }
            }
            return null;
        };
        $attrs = static function (array $attributes) use ($escaper): string {
            $out = '';
            foreach ($attributes as $key => $value) {
                if ($value === null || $value === false || $value === '') {
                    continue;
                }
                if ($value === true) {
                    $out .= ' ' . $key;
                    continue;
                }
                $tokens = in_array($key, ['class', 'aria-describedby', 'aria-labelledby'], true)
                    ? explode(' ', (string)$value) : [(string)$value];
                $out .= ' ' . $key . '="' . implode(' ', array_map([$escaper, 'escapeHtmlAttr'], $tokens)) . '"';
            }
            return $out;
        };
        $base = [
            'id' => $controlId,
            'class' => 'ffb-input',
            'name' => 'fields[' . $name . ']',
            'required' => $required,
            'aria-describedby' => $describedBy,
        ];

        return [
            'item' => $item,
            'formId' => $formId,
            'name' => $name,
            'type' => $type,
            'settings' => $settings,
            'required' => $required,
            'help' => $help,
            'controlId' => $controlId,
            'helpId' => $helpId,
            'errorId' => $errorId,
            'describedBy' => $describedBy,
            'width' => $width,
            'layout' => $layout,
            'labelHtml' => $labelHtml,
            'ruleValue' => $ruleValue,
            'attrs' => $attrs,
            'base' => $base,
            'dataAttrs' => $attrs([
                'data-ffb-item' => $item['id'] ?? '',
                'data-ffb-name' => $name,
                'data-ffb-type' => $type,
            ]),
            'wrapperClass' => 'ffb-item ffb-field ffb-type-' . $type . ' ffb-w-' . $width,
            'cssClass' => trim((string)($item['css_class'] ?? '')),
        ];
    }

    /**
     * Explicit accept wins; otherwise the field extensions limited to the global allowlist
     * (the whole allowlist when the field names none).
     *
     * @param array<string, mixed> $settings
     * @param string[] $globalExtensions
     */
    public static function fileAccept(array $settings, array $globalExtensions): string
    {
        $accept = (string)($settings['accept'] ?? '');
        if ($accept !== '') {
            return $accept;
        }
        $normalise = static fn ($ext): string => strtolower(ltrim(trim((string)$ext), '.'));
        $global = array_map($normalise, $globalExtensions);
        $field = is_array($settings['extensions'] ?? null) ? array_filter(array_map($normalise, $settings['extensions'])) : [];
        $allowed = $field === [] ? $global : array_values(array_intersect($field, $global));

        return implode(',', array_map(static fn (string $ext): string => '.' . $ext, array_unique($allowed)));
    }
}
