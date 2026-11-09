<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Magento\Framework\Controller\AbstractResult;

/** Guest thread responses: never cached, indexed or leaked through the Referer header. */
class PrivateResponse
{
    public const HEADERS = [
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    /**
     * @template T of AbstractResult
     * @param T $result
     * @return T
     */
    public static function apply(AbstractResult $result): AbstractResult
    {
        foreach (self::HEADERS as $name => $value) {
            $result->setHeader($name, $value, true);
        }
        return $result;
    }
}
