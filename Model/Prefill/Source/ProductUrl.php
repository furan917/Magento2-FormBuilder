<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

class ProductUrl extends AbstractProductSource
{
    protected const TYPES = ['url', 'text', 'hidden'];

    public function getCode(): string
    {
        return 'product.url';
    }

    public function getLabel(): string
    {
        return (string)__('Product URL');
    }

    /** @param array<string, mixed> $product */
    protected function fromProduct(array $product): string
    {
        return (string)$product['url'];
    }
}
