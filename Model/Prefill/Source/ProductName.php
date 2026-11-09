<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

class ProductName extends AbstractProductSource
{

    public function getCode(): string
    {
        return 'product.name';
    }

    public function getLabel(): string
    {
        return (string)__('Product name');
    }

    /** @param array<string, mixed> $product */
    protected function fromProduct(array $product): string
    {
        return (string)$product['name'];
    }
}
