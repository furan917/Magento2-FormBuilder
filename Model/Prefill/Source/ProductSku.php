<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

class ProductSku extends AbstractProductSource
{

    public function getCode(): string
    {
        return 'product.sku';
    }

    public function getLabel(): string
    {
        return (string)__('Product SKU');
    }

    /** @param array<string, mixed> $product */
    protected function fromProduct(array $product): string
    {
        return (string)$product['sku'];
    }
}
