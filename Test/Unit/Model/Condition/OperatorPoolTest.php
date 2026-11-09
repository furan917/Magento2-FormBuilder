<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Condition;

use Furan\FormBuilder\Model\Condition\Operator\Equals;
use Furan\FormBuilder\Model\Condition\OperatorPool;
use Furan\FormBuilder\Test\Unit\Helper\ArrayAwareOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OperatorPoolTest extends TestCase
{
    public function testItLogsAndSkipsAPoolItemWhoseKeyDiffersFromItsCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');
        $pool = new OperatorPool($logger, [
            'equals' => new Equals(),
            'wrong_key' => new ArrayAwareOperator('sounds_like'),
            'not_an_operator' => new \stdClass(),
        ]);

        $this->assertSame(['equals'], array_keys($pool->all()));
        $this->assertTrue($pool->has('equals'));
        $this->assertFalse($pool->has('sounds_like'));
        $this->assertInstanceOf(Equals::class, $pool->get('equals'));
    }

    public function testItThrowsForAnUnknownOperatorOnGet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OperatorPool($this->createStub(LoggerInterface::class)))->get('nope');
    }
}
