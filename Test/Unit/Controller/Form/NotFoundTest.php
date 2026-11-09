<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Form;

use Furan\FormBuilder\Controller\Form\NotFound;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

class NotFoundTest extends TestCase
{
    public function testItReturnsA404WithATitleAndNoStoreCacheHeadersFromTheNotFoundAction(): void
    {
        $page = $this->createMock(Page::class);
        $page->expects($this->once())->method('setHttpResponseCode')->with(404)->willReturnSelf();
        $page->expects($this->once())->method('setHeader')
            ->with('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)->willReturnSelf();
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('set')->with('Form not found');
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page->method('getConfig')->willReturn($config);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $this->assertSame($page, (new NotFound($factory))->execute());
    }
}
