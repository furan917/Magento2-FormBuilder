<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Api;

use Furan\FormBuilder\Api\SpamResult;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

class SpamResultTest extends TestCase
{
    public function testItDescribesPassRejectAndSilentOutcomes(): void
    {
        $this->assertTrue(SpamResult::pass()->isPass());
        $reject = SpamResult::reject('x', new Phrase('No'), 429);
        $this->assertTrue($reject->isReject());
        $this->assertSame(['x', 'No', 429], [$reject->getCode(), (string)$reject->getMessage(), $reject->getHttpCode()]);
        $this->assertSame(400, SpamResult::reject('x')->getHttpCode());
        $this->assertNull(SpamResult::reject('x')->getMessage());
        $silent = SpamResult::silent('bot');
        $this->assertTrue($silent->isSilent());
        $this->assertFalse($silent->isPass() || $silent->isReject());
    }
}
