<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\FieldType\ServerValueTypeInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;

/**
 * A third-party type whose value the server owns: it is not Hidden, it only declares the capability.
 */
class StampType extends AbstractFieldType implements ServerValueTypeInterface
{
    public const CODE = 'stamp';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Stamp';
    }
}
