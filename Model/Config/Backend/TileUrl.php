<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class TileUrl extends Value
{
    private const ALLOWED_PLACEHOLDERS = ['z', 'x', 'y', 's', 'r', '-y'];

    /**
     * Returns an error message, or null when the template is usable by Leaflet.
     */
    public static function validate(string $template): ?string
    {
        if (!preg_match('#^https://[^\s"\'<>]+$#i', $template)) {
            return (string)__('The tile URL must start with https:// and contain no spaces or quotes.');
        }
        preg_match_all('/\{([^{}]*)\}/', $template, $m);
        foreach ($m[1] as $name) {
            if (!in_array($name, self::ALLOWED_PLACEHOLDERS, true)) {
                return (string)__('The tile URL may only use the placeholders {z}, {x}, {y}, {s}, {r} and {-y}.');
            }
        }
        $used = array_flip($m[1]);
        if (!isset($used['z'], $used['x']) || !(isset($used['y']) || isset($used['-y']))) {
            return (string)__('The tile URL must contain the {z}, {x} and {y} placeholders.');
        }
        $stripped = (string)preg_replace('/\{[^{}]*\}/', 'a', $template);
        if (str_contains($stripped, '{') || str_contains($stripped, '}')
            || !parse_url($stripped, PHP_URL_HOST)
        ) {
            return (string)__('The tile URL is not valid.');
        }

        return null;
    }

    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '' && ($error = self::validate($value)) !== null) {
            throw new LocalizedException(__($error));
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
