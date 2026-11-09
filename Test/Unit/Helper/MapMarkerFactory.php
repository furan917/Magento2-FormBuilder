<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Csp\MapMarker;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Magento\Framework\App\DeploymentConfig;

class MapMarkerFactory
{
    public static function create(string $cryptKey = 'k0'): MapMarker
    {
        $config = new class ($cryptKey) extends DeploymentConfig {
            public function __construct(private readonly string $key)
            {
            }

            public function get($key = null, $defaultValue = null)
            {
                return $key === 'crypt/key' ? $this->key : $defaultValue;
            }
        };

        return new MapMarker(new SigningKeys($config));
    }
}
