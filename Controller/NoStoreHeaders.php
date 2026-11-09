<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller;

use Magento\Framework\Controller\AbstractResult;

/**
 * Per-visitor storefront JSON: never cached or indexed.
 */
trait NoStoreHeaders
{
    /**
     * @template T of AbstractResult
     * @param T $result
     * @return T
     */
    private function noStore(AbstractResult $result): AbstractResult
    {
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        return $result;
    }
}
