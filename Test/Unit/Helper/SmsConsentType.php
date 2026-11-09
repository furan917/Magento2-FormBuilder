<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\FieldType\ConsentTypeInterface;
use Furan\FormBuilder\Model\FieldType\Checkbox;

/**
 * A third-party consent type: it is not Newsletter, it only declares the capability.
 */
class SmsConsentType extends Checkbox implements ConsentTypeInterface
{
    public const CODE = 'sms_optin';

    public function getCode(): string
    {
        return self::CODE;
    }
}
