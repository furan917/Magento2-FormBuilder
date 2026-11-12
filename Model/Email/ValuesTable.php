<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

use Furan\FormBuilder\Api\Data\DisplayContext;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Magento\Framework\Escaper;

/** Answers as the admin view shows them, with option labels from the (store-localised) schema and dates and countries for $context. */
class ValuesTable
{
    public function __construct(private readonly Escaper $escaper, private readonly AnswerView $answerView)
    {
    }

    /**
     * @param array<int, array{name: string, label: string, type: string, value: mixed}> $payload
     * @param array<string, mixed> $schema
     */
    public function toHtml(array $payload, array $schema, DisplayContext $context): string
    {
        $fields = SchemaFields::byName($schema);
        $rows = '';
        foreach ($payload as $entry) {
            $rows .= '<tr><th align="left" valign="top" style="padding:4px 12px 4px 0">'
                . $this->encode((string)($entry['label'] ?? ''))
                . '</th><td valign="top" style="padding:4px 0">'
                . str_replace(["\r\n", "\r", "\n"], '<br />', $this->encode($this->stringify($entry, $fields, $context)))
                . '</td></tr>';
        }

        return '<table class="ffb-values" cellpadding="0" cellspacing="0">' . $rows . '</table>';
    }

    /** @param array{id?: mixed, sku?: mixed, name?: mixed, url?: mixed, price?: mixed, currency?: mixed}|null $context */
    public function contextHtml(?array $context): string
    {
        if ($context === null || $context === []) {
            return '';
        }
        $text = static fn(string $key): string => is_scalar($context[$key] ?? null) ? (string)$context[$key] : '';
        $rows = [
            [(string)__('Product'), $this->encode($text('name'))],
            [(string)__('SKU'), $this->encode($text('sku'))],
        ];
        if ($text('price') !== '' && is_numeric($text('price'))) {
            $rows[] = [(string)__('Price'), $this->encode(
                trim(number_format((float)$text('price'), 2, '.', '') . ' ' . $text('currency'))
            )];
        }
        $url = $text('url');
        if (preg_match('#^https?://#i', $url) === 1) {
            $safe = str_replace(['{', '}'], ['%7B', '%7D'], $this->escaper->escapeUrl($url));
            $rows[] = [(string)__('Link'), '<a href="' . $safe . '">' . $this->encode($url) . '</a>'];
        }
        $html = '';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th align="left" valign="top" style="padding:4px 12px 4px 0">' . $this->encode($label)
                . '</th><td valign="top" style="padding:4px 0">' . $value . '</td></tr>';
        }

        return '<table class="ffb-product" cellpadding="0" cellspacing="0">' . $html . '</table>';
    }

    /**
     * Plain text rows for `{{for item in values}}`; the template escapes them, braces are encoded here.
     *
     * @param array<int, array{name: string, label: string, type: string, value: mixed}> $payload
     * @param array<string, mixed> $schema
     * @return array<int, array{label: string, value: string}>
     */
    public function toList(array $payload, array $schema, DisplayContext $context): array
    {
        $fields = SchemaFields::byName($schema);
        $list = [];
        foreach ($payload as $entry) {
            $list[] = [
                'label' => MessageRenderer::braces((string)($entry['label'] ?? '')),
                'value' => MessageRenderer::braces($this->stringify($entry, $fields, $context)),
            ];
        }

        return $list;
    }

    private function encode(string $text): string
    {
        return str_replace(['{', '}'], ['&#123;', '&#125;'], $this->escaper->escapeHtml($text));
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $fields
     */
    private function stringify(array $entry, array $fields, DisplayContext $context): string
    {
        $value = $entry['value'] ?? '';
        if (is_array($value) && isset($value['name'], $value['size'])) {
            return (string)$value['name'] . ' (' . ValueFormatter::fileSize((int)$value['size']) . ')';
        }
        $entry['field'] = $fields[(string)($entry['name'] ?? '')] ?? null;
        return implode(', ', $this->answerView->values($entry, $context));
    }
}
