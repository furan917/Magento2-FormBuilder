<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\Phrase\RendererInterface;

class Translated
{
    /** Runs $callback while every phrase renders as "<prefix><text>" with its %N arguments filled in. */
    public static function with(string $prefix, callable $callback): mixed
    {
        $previous = (new \ReflectionClass(Phrase::class))->getStaticPropertyValue('renderer', null);
        Phrase::setRenderer(new class ($prefix) implements RendererInterface {
            public function __construct(private readonly string $prefix)
            {
            }

            public function render(array $source, array $arguments): string
            {
                $text = $this->prefix . end($source);
                foreach (array_values($arguments) as $index => $argument) {
                    $text = str_replace('%' . ($index + 1), (string)$argument, $text);
                }
                return $text;
            }
        });
        try {
            return $callback();
        } finally {
            Phrase::setRenderer($previous ?? new Placeholder());
        }
    }
}
