<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill;

class LockedValues
{
    public function __construct(
        private readonly PrefillService $prefillService,
        private readonly Sources $sources
    ) {
    }

    /** @return array<string, mixed>|null */
    public function product(int $productId, int $storeId): ?array
    {
        return $productId > 0 ? $this->prefillService->product($productId, $storeId) : null;
    }

    /**
     * @param array<string, mixed>|null $product
     * @return array{id: int, sku: string, name: string, url: string, price: float, currency: string}|null
     */
    public function snapshot(?array $product): ?array
    {
        if ($product === null) {
            return null;
        }
        return [
            'id' => (int)$product['id'],
            'sku' => (string)$product['sku'],
            'name' => (string)$product['name'],
            'url' => (string)$product['url'],
            'price' => (float)$product['price'],
            'currency' => (string)$product['currency'],
        ];
    }

    /**
     * Overwrites posted answers of locked prefill fields with server-side data.
     *
     * @param list<array<string, mixed>> $fields schema field items
     * @param array<string, mixed>|null $product
     * @param array<string, mixed> $posted raw posted fields
     * @return array<string, mixed>
     */
    public function apply(array $fields, array $posted, int $customerId, ?array $product, int $storeId): array
    {
        $locked = array_filter($fields, fn(array $f): bool => !empty($f['locked']) && (string)($f['prefill'] ?? '') !== '');
        // A lock never falls back to the browser: unknown, removed or unsuitable sources blank the answer.
        $usable = [];
        foreach ($locked as $field) {
            if ($this->sources->suits($field['prefill'], (string)($field['type'] ?? ''))) {
                $usable[] = $field;
            } else {
                $posted[$field['name']] = '';
            }
        }
        $customerSources = [];
        foreach ($usable as $field) {
            if (!$this->sources->isProduct($field['prefill'])) {
                $customerSources[] = $field['prefill'];
            }
        }
        $customerValues = $customerId > 0 && $customerSources !== []
            ? $this->prefillService->valuesFor($customerId, $customerSources, $storeId)
            : [];
        $productContext = $this->productContext($product, $storeId);

        foreach ($usable as $field) {
            $code = $field['prefill'];
            if ($this->sources->isProduct($code)) {
                $value = $this->sources->get($code)?->resolve($productContext);
                $posted[$field['name']] = is_string($value) ? $value : '';
                continue;
            }
            if ($customerId > 0) {
                $value = $customerValues[$code] ?? '';
                $posted[$field['name']] = is_array($value) ? $value : (string)$value;
            }
        }
        return $posted;
    }

    /**
     * Locked fields whose answer apply() takes from the server; they are never required of the visitor.
     *
     * @param list<array<string, mixed>> $fields schema field items
     * @return list<string>
     */
    public function serverOwned(array $fields, int $customerId): array
    {
        $names = [];
        foreach ($fields as $field) {
            $code = (string)($field['prefill'] ?? '');
            if (!empty($field['locked']) && $code !== '' && ($customerId > 0 || $this->sources->isProduct($code)
                || !$this->sources->suits($code, (string)($field['type'] ?? '')))) {
                $names[] = (string)$field['name'];
            }
        }
        return $names;
    }

    /** @param array<string, mixed>|null $product */
    private function productContext(?array $product, int $storeId): PrefillContext
    {
        return new PrefillContext($storeId, $product, static fn() => null);
    }
}
