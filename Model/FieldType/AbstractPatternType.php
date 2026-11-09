<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

abstract class AbstractPatternType extends AbstractFieldType
{
    abstract protected function getPattern(): string;

    protected function isFormatValid(string $value): bool
    {
        return preg_match($this->getPattern(), $value) === 1;
    }
}
