<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;

/** Custom type (any code) that only declares its admin view kind. */
class AdminViewKindType extends AbstractFieldType implements AdminViewTypeInterface
{
    public function __construct(private readonly string $code, private readonly ?string $kind)
    {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->code;
    }

    public function adminViewKind(array $field): ?string
    {
        return $this->kind;
    }
}
