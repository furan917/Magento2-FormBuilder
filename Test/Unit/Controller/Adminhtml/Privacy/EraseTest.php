<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Privacy;

use Furan\FormBuilder\Controller\Adminhtml\Privacy\Erase;
use Furan\FormBuilder\Model\Privacy\Eraser;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Psr\Log\LoggerInterface;

class EraseTest extends ControllerTestCase
{
    private Eraser $eraser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eraser = $this->createStub(Eraser::class);
        $this->redirect->method('setPath')->willReturnSelf();
    }

    private function params(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(static fn (string $k) => $params[$k] ?? null);
    }

    private function controller(): Erase
    {
        return new Erase($this->context, $this->eraser, $this->createStub(LoggerInterface::class));
    }

    public function testItErasesACustomerAfterConfirmation(): void
    {
        $eraser = $this->eraser = $this->createMock(Eraser::class);
        $this->params(['customer_id' => '7', 'confirm' => '1']);
        $eraser->expects($this->once())->method('eraseCustomer')->with(7)->willReturn(3);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')->with(
            $this->callback(static fn ($m) => str_contains((string)$m, '3 submission(s)') && str_contains((string)$m, 'within the hour'))
        );

        $this->assertSame($this->redirect, $this->controller()->execute());
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller());
        $this->assertSame('Furan_FormBuilder::privacy', Erase::ADMIN_RESOURCE);
    }

    public function testItErasesByEmailAfterConfirmation(): void
    {
        $eraser = $this->eraser = $this->createMock(Eraser::class);
        $this->params(['email' => 'a@b.c', 'confirm' => '1']);
        $eraser->expects($this->once())->method('eraseEmail')->with('a@b.c')->willReturn(1);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');

        $this->controller()->execute();
    }

    public function testItErasesNothingWithoutTheConfirmation(): void
    {
        $eraser = $this->eraser = $this->createMock(Eraser::class);
        $this->params(['customer_id' => '7']);
        $eraser->expects($this->never())->method('eraseCustomer');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller()->execute());
    }

    public function testItErasesNothingWithoutALookupValue(): void
    {
        $eraser = $this->eraser = $this->createMock(Eraser::class);
        $this->params(['confirm' => '1']);
        $eraser->expects($this->never())->method('eraseCustomer');
        $eraser->expects($this->never())->method('eraseEmail');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');

        $this->controller()->execute();
    }
}
