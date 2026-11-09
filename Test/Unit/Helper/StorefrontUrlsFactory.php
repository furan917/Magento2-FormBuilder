<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Url\StorefrontUrls;

/**
 * StorefrontUrls double that prints the same fake hosts as Test\Unit\Block\Stub\TestableForm.
 */
class StorefrontUrlsFactory
{
    public static function create(): StorefrontUrls
    {
        return new class extends StorefrontUrls {
            public function __construct()
            {
            }

            public function route(string $path, array $query = []): string
            {
                return 'https://shop.test/' . $path . ($query === [] ? '' : '?' . http_build_query($query));
            }

            public function asset(string $fileId): string
            {
                return 'https://static.test/' . str_replace('::', '/', $fileId);
            }
        };
    }
}
