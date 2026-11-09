<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/** @api */
class InvalidSchemaException extends LocalizedException
{
    /**
     * @param string[] $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(new Phrase($errors[0] ?? 'The form schema is invalid.'));
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
