<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Privacy;

use Furan\FormBuilder\Controller\Adminhtml\Privacy\Preview;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;

class PreviewTest extends ControllerTestCase
{
    private function controller(PageFactory $factory): Preview
    {
        return new Preview($this->context, $factory);
    }

    public function testItShowsTheConfirmationPageForALookupValue(): void
    {
        $this->request->method('getParam')->willReturnCallback(static fn (string $k) => ['email' => 'a@b.c'][$k] ?? null);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($this->createStub(Title::class));
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->expects($this->once())->method('setActiveMenu')->with('Furan_FormBuilder::privacy');
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $this->assertSame($page, $this->controller($factory)->execute());
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller($factory));
        $this->assertSame('Furan_FormBuilder::privacy', Preview::ADMIN_RESOURCE);
    }

    public function testItRedirectsBackWithoutALookupValue(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $factory = $this->createMock(PageFactory::class);
        $factory->expects($this->never())->method('create');

        $this->assertSame($this->redirect, $this->controller($factory)->execute());
    }
}
