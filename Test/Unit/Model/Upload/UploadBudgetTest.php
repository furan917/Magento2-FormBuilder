<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Upload\UploadBudget;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use PHPUnit\Framework\TestCase;

class UploadBudgetTest extends TestCase
{
    private function budget(int $bytes, MemoryCounter $counter): UploadBudget
    {
        $config = $this->createStub(Config::class);
        $config->method('getUploadBudgetBytes')->willReturn($bytes);

        return new UploadBudget($counter, $config, fn (): int => 7200);
    }

    public function testItReservesBytesPerFormPerHourAcrossAllVisitors(): void
    {
        $counter = new MemoryCounter(fn (): int => 7200);
        $budget = $this->budget(100, $counter);

        $this->assertTrue($budget->reserve(7, 60));
        $this->assertTrue($budget->reserve(7, 40));
        $this->assertFalse($budget->reserve(7, 1));
        $this->assertTrue($budget->reserve(8, 100), 'other forms have their own budget');
        $this->assertSame(['ffb_ub_7|2', 'ffb_ub_8|2'], array_keys($counter->rows));
    }

    public function testItAllowsEverythingWhenTheBudgetIsZeroOrNothingIsUploaded(): void
    {
        $counter = new MemoryCounter();
        $this->assertTrue($this->budget(0, $counter)->reserve(7, PHP_INT_MAX));
        $this->assertTrue($this->budget(10, $counter)->reserve(7, 0));
        $this->assertSame([], $counter->rows);
    }
}
