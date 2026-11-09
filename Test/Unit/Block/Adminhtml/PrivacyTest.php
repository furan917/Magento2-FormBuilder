<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml;

use Furan\FormBuilder\Block\Adminhtml\Privacy;
use Furan\FormBuilder\Model\Privacy\Eraser;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\FormKey;
use Magento\Backend\Block\Template\Context;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Test\Unit\Helper\BackendBlockObjectManager;

class PrivacyTest extends TestCase
{
    use BackendBlockObjectManager;

    private function block(string $action, array $params, Eraser $eraser): Privacy
    {
        $request = $this->createStub(Http::class);
        $request->method('getActionName')->willReturn($action);
        $request->method('getParam')->willReturnCallback(static fn (string $k) => $params[$k] ?? null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getFormKey')->willReturn($this->createStub(FormKey::class));

        return new Privacy($context, $eraser);
    }

    public function testItPreviewsTheEraseOnlyOnThePreviewAction(): void
    {
        $eraser = $this->createMock(Eraser::class);
        $eraser->expects($this->never())->method('preview');

        $this->assertNull($this->block('index', ['email' => 'a@b.c'], $eraser)->getErasePreview());
    }

    public function testItPreviewsAnEmailLookup(): void
    {
        $eraser = $this->createMock(Eraser::class);
        $eraser->expects($this->once())->method('preview')->with(0, 'a@b.c')
            ->willReturn(['total' => 2, 'accounts' => 1, 'forms' => [['form_id' => 3, 'title' => 'Contact', 'count' => 2]]]);
        $block = $this->block('preview', ['email' => ' a@b.c '], $eraser);

        $this->assertSame(
            ['field' => 'email', 'value' => 'a@b.c', 'total' => 2, 'accounts' => 1,
                'forms' => [['form_id' => 3, 'title' => 'Contact', 'count' => 2]]],
            $block->getErasePreview()
        );
        $block->getErasePreview();
    }

    public function testItPrefersTheCustomerIdLikeTheEraseAction(): void
    {
        $eraser = $this->createMock(Eraser::class);
        $eraser->expects($this->once())->method('preview')->with(7, '')
            ->willReturn(['total' => 0, 'accounts' => 1, 'forms' => []]);

        $preview = $this->block('preview', ['customer_id' => '7', 'email' => 'a@b.c'], $eraser)->getErasePreview();

        $this->assertSame(['customer_id', '7'], [$preview['field'], $preview['value']]);
    }
}
