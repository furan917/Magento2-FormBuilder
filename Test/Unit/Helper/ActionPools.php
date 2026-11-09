<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Condition\ActionPool;
use Psr\Log\NullLogger;

class ActionPools
{
    /** Every built-in action of etc/di.xml, plus any extra actions. */
    public static function builtIn(array $extra = []): ActionPool
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        $actions = [];
        foreach ($xml->xpath("//type[@name='" . ActionPool::class . "']/arguments/argument[@name='actions']/item") as $item) {
            $actions[(string)$item['name']] = new (trim((string)$item))();
        }
        return new ActionPool(new NullLogger(), $actions + $extra);
    }
}
