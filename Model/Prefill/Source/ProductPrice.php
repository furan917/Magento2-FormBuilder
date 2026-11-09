<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

class ProductPrice extends AbstractProductSource
{
    protected const TYPES = ['number', 'text', 'hidden'];

    public function getCode(): string
    {
        return 'product.price';
    }

    public function getLabel(): string
    {
        return (string)__('Product price');
    }

    /** @param array<string, mixed> $product */
    protected function fromProduct(array $product): string
    {
        return (string)$product['price'];
    }
}
