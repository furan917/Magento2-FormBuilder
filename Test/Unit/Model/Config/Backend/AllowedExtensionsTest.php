<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Config\Backend;

use Furan\FormBuilder\Model\Config\Backend\AllowedExtensions;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class AllowedExtensionsTest extends TestCase
{
    private function save(string $value): string
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        $model = new AllowedExtensions(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class)
        );
        $model->setValue($value);
        $model->beforeSave();

        return (string)$model->getValue();
    }

    public function testItNormalisesASupportedList(): void
    {
        self::assertSame('pdf,jpg,docx', $this->save(' PDF, .jpg ,docx,pdf,'));
    }

    public function testItReportsExtensionsWhoseContentCannotBeChecked(): void
    {
        try {
            $this->save('pdf,doc,xls,zip');
            self::fail('Expected an exception');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('doc, xls, zip', $e->getMessage());
            self::assertStringContainsString('docx', $e->getMessage());
        }
    }

    public function testItReportsBlockedExtensions(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('php');
        $this->save('pdf,php');
    }
}
