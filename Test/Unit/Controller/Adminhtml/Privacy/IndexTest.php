<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Privacy;

use Furan\FormBuilder\Controller\Adminhtml\Privacy\Index;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;

class IndexTest extends ControllerTestCase
{
    public function testItShowsThePrivacyPageOnAGetWithTheViewPermission(): void
    {
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($this->createStub(Title::class));
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->expects($this->once())->method('setActiveMenu')->with('Furan_FormBuilder::privacy');
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $controller = new Index($this->context, $factory);

        $this->assertSame('Furan_FormBuilder::privacy', Index::ADMIN_RESOURCE);
        $this->assertInstanceOf(HttpGetActionInterface::class, $controller);
        $this->assertSame($page, $controller->execute());
    }
}
