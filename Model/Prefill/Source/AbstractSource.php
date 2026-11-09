<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Furan\FormBuilder\Api\PrefillSourceInterface;

abstract class AbstractSource implements PrefillSourceInterface
{
    protected const TYPES = ['text', 'hidden'];

    public function suitsType(string $typeCode): bool
    {
        return in_array($typeCode, static::TYPES, true);
    }
}
