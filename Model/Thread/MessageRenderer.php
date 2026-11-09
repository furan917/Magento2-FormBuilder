<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Magento\Framework\Escaper;

/** Store replies are markdown; customer-authored text is never interpreted (escaped + line breaks only). */
class MessageRenderer
{
    public function __construct(private readonly Escaper $escaper, private readonly MiniMarkdown $markdown)
    {
    }

    public function html(string $author, string $body): string
    {
        return $author === 'admin'
            ? $this->markdown->renderMessage($body)
            : nl2br($this->escaper->escapeHtml($body));
    }

    public function emailHtml(string $author, string $body): string
    {
        return self::braces($this->html($author, $body));
    }

    /** Email template filters run {{...}} directives; entities keep user text inert (CVE-2022-24086 class). */
    public static function braces(string $text): string
    {
        return str_replace(['{', '}'], ['&#123;', '&#125;'], $text);
    }
}
