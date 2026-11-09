<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\PrefillSourceInterface;
use Furan\FormBuilder\Api\Data\PrefillContextInterface;

/** Custom prefill source that records its contexts and returns a fixed value. */
class ArrayPrefillSource implements PrefillSourceInterface
{
    /** @var PrefillContextInterface[] */
    public array $contexts = [];

    public function __construct(
        private readonly string $code = 'customer.loyalty',
        private readonly string $group = 'customer',
        private readonly array $types = [],
        private readonly string|array|null $value = 'L-1'
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return 'Loyalty number';
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function suitsType(string $typeCode): bool
    {
        return $this->types === [] || in_array($typeCode, $this->types, true);
    }

    public function resolve(PrefillContextInterface $context): string|array|null
    {
        $this->contexts[] = $context;
        return $this->value;
    }
}
