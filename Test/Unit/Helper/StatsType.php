<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;

/** Custom type with a configurable chart kind and free-text flag. */
class StatsType extends AbstractFieldType implements StatsAwareTypeInterface, FreeTextTypeInterface
{
    public function __construct(private readonly ?int $kind, private readonly bool $freeText = false)
    {
    }

    public function getCode(): string
    {
        return 'stat';
    }

    public function getLabel(): string
    {
        return 'Stat';
    }

    public function statsKind(array $field): ?int
    {
        return $this->kind;
    }

    public function isFreeText(): bool
    {
        return $this->freeText;
    }
}
