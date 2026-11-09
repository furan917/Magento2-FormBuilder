<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: adds per-item render data for the storefront field template.
 *
 * The hints reach the template as `$item['render']` and are what `Block\ItemContext` reads (`layout` = `inline`
 * for an inline option layout). Stored schema keys never become hints. The page is cached per form and store
 * view, so return per-form and per-store data only, never per-visitor data.
 *
 * @api
 */
interface RenderHintsInterface
{
    /**
     * @param array<string, mixed> $field the schema field being rendered
     * @param int $storeId rendering store view
     * @return array<string, mixed>
     */
    public function renderHints(array $field, int $storeId): array;
}
