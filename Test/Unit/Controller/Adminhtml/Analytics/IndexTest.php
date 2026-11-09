<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Analytics;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Adminhtml\Analytics;
use Furan\FormBuilder\Controller\Adminhtml\Analytics\Index;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Stats\Folder;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;

class IndexTest extends ControllerTestCase
{
    private Folder $folder;
    private Config $config;
    private FormRepositoryInterface $repository;
    private Page $page;
    private Analytics $block;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    private function fixture(?Analytics $block = null, ?Folder $folder = null): void
    {
        $this->folder = $folder ?? $this->createStub(Folder::class);
        $this->config = $this->createStub(Config::class);
        $this->repository = $this->createStub(FormRepositoryInterface::class);
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact');
        $this->repository->method('getById')->willReturn($form);
        $this->block = $block ?? $this->createStub(Analytics::class);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with('furan_formbuilder_analytics')->willReturn($this->block);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($this->createStub(Title::class));
        $this->page = $this->createStub(Page::class);
        $this->page->method('getConfig')->willReturn($pageConfig);
        $this->page->method('getLayout')->willReturn($layout);
        $this->expectingRequest()->method('getParam')->with('form_id')->willReturn('5');
    }

    private function controller(): Index
    {
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($this->page);
        return new Index($this->context, $factory, $this->repository, $this->folder, $this->config);
    }

    public function testItRequiresTheAnalyticsPermission(): void
    {
        $this->assertSame('Furan_FormBuilder::analytics', Index::ADMIN_RESOURCE);
        $this->assertInstanceOf(HttpGetActionInterface::class, $this->controller());
    }

    public function testItFoldsPendingJournalRowsForTheFormWithinATimeBudgetBeforeReading(): void
    {
        $folder = $this->createMock(Folder::class);
        $block = $this->createMock(Analytics::class);
        $this->fixture(folder: $folder, block: $block);
        $this->config->method('isAnalyticsEnabled')->willReturn(true);
        $folder->expects($this->once())->method('foldForm')->with(5, 2)->willReturn(7);
        $block->expects($this->once())->method('setData')->with('pending', 7);

        $this->assertSame($this->page, $this->controller()->execute());
    }

    public function testItDoesNotFoldWhenAnalyticsIsDisabled(): void
    {
        $folder = $this->createMock(Folder::class);
        $this->fixture(folder: $folder);
        $this->config->method('isAnalyticsEnabled')->willReturn(false);
        $folder->expects($this->never())->method('foldForm');

        $this->assertSame($this->page, $this->controller()->execute());
    }

    public function testItRedirectsToTheGridForAnUnknownForm(): void
    {
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('nope')));
        $this->repository = $repository;
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('furanform/form/')->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller()->execute());
    }

    public function testItRendersThePageWhenTheAnalyticsBlockIsMissingFromTheLayout(): void
    {
        $this->config->method('isAnalyticsEnabled')->willReturn(true);
        $this->folder->method('foldForm')->willReturn(3);
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturn(false);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($this->createStub(Title::class));
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('getLayout')->willReturn($layout);
        $this->page = $page;

        $this->assertSame($this->page, $this->controller()->execute());
    }
}
