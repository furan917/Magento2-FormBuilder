<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Furan\FormBuilder\Api\Data\PrefillContextInterface;
use Furan\FormBuilder\Model\Prefill\Sources;

abstract class AbstractProductSource extends AbstractSource
{
    public function getGroup(): string
    {
        return Sources::GROUP_PRODUCT;
    }

    public function resolve(PrefillContextInterface $context): string|array|null
    {
        $product = $context->getProduct();
        return $product === null ? '' : $this->fromProduct($product);
    }

    /** @param array<string, mixed> $product */
    abstract protected function fromProduct(array $product): string;
}
