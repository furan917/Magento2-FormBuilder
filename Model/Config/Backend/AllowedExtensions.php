<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Config\Backend;

use Furan\FormBuilder\Model\Upload\FileValidator;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * An extension the validator has no content check for would silently refuse every upload, so it is reported here.
 */
class AllowedExtensions extends Value
{
    public function beforeSave()
    {
        $extensions = [];
        foreach (explode(',', (string)$this->getValue()) as $part) {
            $extension = strtolower(ltrim(trim($part), '.'));
            if ($extension !== '') {
                $extensions[$extension] = $extension;
            }
        }
        $supported = FileValidator::supportedExtensions();
        $unsupported = array_diff($extensions, $supported);
        if ($unsupported !== []) {
            throw new LocalizedException(__(
                'These file types cannot be allowed because their content cannot be checked: %1. Supported types: %2.',
                implode(', ', $unsupported),
                implode(', ', $supported)
            ));
        }
        $this->setValue(implode(',', $extensions));

        return parent::beforeSave();
    }
}
