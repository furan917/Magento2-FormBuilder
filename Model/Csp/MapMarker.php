<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Csp;

use Furan\FormBuilder\Model\Security\SigningKeys;

/**
 * Value of the map canvas attribute that tells MapPagePolicies a page shows a map. Visitor text echoed into a
 * page cannot carry it, so a crafted link cannot widen that page's CSP.
 */
class MapMarker
{
    public const ATTRIBUTE = 'data-ffb-map-canvas';
    // Own derivation label, so signatures never cross token types.
    private const KEY_LABEL = 'furan_formbuilder_map_marker';

    public function __construct(private readonly SigningKeys $keys)
    {
    }

    /** Signed with the newest key; empty when no crypt key is configured. */
    public function value(int $storeId): string
    {
        $keys = $this->keys->all(self::KEY_LABEL);
        return $keys === [] ? '' : $this->sign($storeId, $keys[0]);
    }

    /**
     * The attribute as rendered, for every key version, so pages cached before a key rotation still match.
     *
     * @return string[]
     */
    public function attributes(int $storeId): array
    {
        return array_map(
            fn (string $key): string => self::ATTRIBUTE . '="' . $this->sign($storeId, $key) . '"',
            $this->keys->all(self::KEY_LABEL)
        );
    }

    private function sign(int $storeId, string $key): string
    {
        return substr(hash_hmac('sha256', 'map-canvas|' . $storeId, $key), 0, 32);
    }
}
