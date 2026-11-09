<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Map;

use Furan\FormBuilder\Api\Data\RequestPolicy;
use Magento\Framework\App\DeploymentConfig;

/**
 * Outbound policy for map address search: https, port 443 plus the env.php list, no IP literals, no dev bypasses.
 */
class MapRequestPolicy
{
    public const MAX_RESPONSE_BYTES = 262144;

    private const TIMEOUT_MS = 5000;
    private const PORTS_PATH = 'furan_formbuilder/map/ports';

    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    public function build(): RequestPolicy
    {
        return new RequestPolicy(
            false,
            array_merge([443], $this->extraPorts()),
            [],
            [],
            [],
            false,
            self::TIMEOUT_MS,
            self::TIMEOUT_MS,
            self::MAX_RESPONSE_BYTES
        );
    }

    /**
     * @return int[]
     */
    private function extraPorts(): array
    {
        $configured = $this->deploymentConfig->get(self::PORTS_PATH);
        $ports = [];
        foreach (is_array($configured) ? $configured : [] as $port) {
            if (is_int($port) || (is_string($port) && preg_match('/^[1-9]\d{0,4}$/D', $port) === 1)) {
                $ports[] = (int)$port;
            }
        }

        return $ports;
    }
}
