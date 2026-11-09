<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;

/**
 * Builds mail as the recipient's store: admin requests and queue consumers otherwise translate text built in
 * code (subjects, labels) into the admin's or the default store's language.
 */
class StoreScope
{
    public function __construct(private readonly Emulation $emulation)
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(int $storeId, callable $work): mixed
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            return $work();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
