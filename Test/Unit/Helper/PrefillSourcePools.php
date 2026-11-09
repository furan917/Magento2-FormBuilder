<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Prefill\Source\CustomerGender;
use Furan\FormBuilder\Model\Prefill\Sources;
use Magento\Customer\Api\CustomerMetadataInterface;
use Psr\Log\NullLogger;

class PrefillSourcePools
{
    /** Every built-in source of etc/di.xml, plus any extra sources. */
    public static function builtIn(array $extra = [], ?CustomerMetadataInterface $metadata = null): Sources
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        $sources = [];
        foreach ($xml->xpath("//type[@name='" . Sources::class . "']/arguments/argument[@name='sources']/item") as $item) {
            $class = trim((string)$item);
            $sources[(string)$item['name']] = $class === CustomerGender::class && $metadata !== null
                ? new CustomerGender($metadata)
                : (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        }
        return new Sources($sources + $extra, new NullLogger());
    }
}
