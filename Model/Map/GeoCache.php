<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Map;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

class GeoCache extends TagScope
{
    public const TYPE_IDENTIFIER = 'furan_formbuilder_geo';
    public const CACHE_TAG = 'FURAN_FFB_GEO';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
