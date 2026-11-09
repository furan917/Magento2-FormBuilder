<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/** A refused endpoint save. The message is the errors joined by "\n"; fieldErrors() keys them by form field. */
class EndpointInputException extends LocalizedException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(private readonly array $fieldErrors)
    {
        parent::__construct(new Phrase(implode("\n", $fieldErrors)));
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
