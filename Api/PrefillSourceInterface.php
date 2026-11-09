<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\PrefillContextInterface;

/**
 * A value a field can be prefilled from, registered in the `sources` argument of Model\Prefill\Sources.
 * Customer-group values reach the browser through the token endpoint; product-group values are always locked and
 * re-read on the server at submit time.
 *
 * @api
 */
interface PrefillSourceInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    /** 'customer' (needs a logged-in customer) or 'product' (needs the product of the page). */
    public function getGroup(): string;

    public function suitsType(string $typeCode): bool;

    /**
     * Read shared data from the context; it loads the customer once for all sources.
     *
     * @return string|array<string, mixed>|null '' or null when there is nothing to prefill; addresses use the `address` value shape
     */
    public function resolve(PrefillContextInterface $context): string|array|null;
}
