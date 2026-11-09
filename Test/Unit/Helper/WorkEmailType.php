<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\FieldType\EmailAddressTypeInterface;
use Furan\FormBuilder\Model\FieldType\Text;

/** A third party email address type: not the built-in Email class and not coded "email". */
class WorkEmailType extends Text implements EmailAddressTypeInterface
{
    public const CODE = 'work_email';

    public function getCode(): string
    {
        return self::CODE;
    }
}
