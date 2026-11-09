<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form;

use Furan\FormBuilder\Controller\Adminhtml\Form\Index;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;

class IndexTest extends ControllerTestCase
{
    public function testItRendersTheFormsGridPageWithTheActiveMenu(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend')->with($this->callback(
            static fn ($t) => (string)$t === 'Forms'
        ));
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($config);
        $page->expects($this->once())->method('setActiveMenu')->with('Furan_FormBuilder::form_list');
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $controller = new Index($this->context, $factory);

        $this->assertInstanceOf(HttpGetActionInterface::class, $controller);
        $this->assertSame($page, $controller->execute());
    }
}
