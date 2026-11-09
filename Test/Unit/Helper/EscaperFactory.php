<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use Psr\Log\NullLogger;
use ReflectionProperty;

/** Builds an Escaper whose lazy ObjectManager lookups are pre-filled, for unit tests. */
class EscaperFactory
{
    public static function create(): Escaper
    {
        $escaper = new Escaper();
        $inline = new class implements InlineInterface {
            public function getAdditionalHtmlAttribute($tagName = null)
            {
                return null;
            }

            public function isAllowed()
            {
                return false;
            }

            public function processResponseBody(&$body, $isJson = false)
            {
                return $this;
            }

            public function getParser()
            {
                return null;
            }
        };

        foreach (['escaper' => new ZendEscaper(), 'translateInline' => $inline, 'logger' => new NullLogger()] as $name => $value) {
            $property = new ReflectionProperty(Escaper::class, $name);
            $property->setValue($escaper, $value);
        }

        return $escaper;
    }
}
