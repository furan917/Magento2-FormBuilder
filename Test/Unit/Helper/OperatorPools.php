<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Condition\OperatorPool;
use Psr\Log\NullLogger;

class OperatorPools
{
    /** Every built-in operator of etc/di.xml, plus any extra operators. */
    public static function builtIn(array $extra = []): OperatorPool
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        $operators = [];
        foreach ($xml->xpath("//type[@name='" . OperatorPool::class . "']/arguments/argument[@name='operators']/item") as $item) {
            $operators[(string)$item['name']] = new (trim((string)$item))();
        }
        return new OperatorPool(new NullLogger(), $operators + $extra);
    }
}
