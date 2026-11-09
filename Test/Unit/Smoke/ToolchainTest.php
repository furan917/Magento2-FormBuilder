<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Smoke;

use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

class ToolchainTest extends TestCase
{
    public function testMagentoClassesAutoload(): void
    {
        $this->assertTrue(class_exists(Escaper::class));
    }

    public function testGeneratedFactoriesAutoload(): void
    {
        $this->assertTrue(class_exists(\Magento\Framework\DataObjectFactory::class));
    }
}
