<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Form\View;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ViewTest extends TestCase
{
    private PageFactory&Stub $pageFactory;
    private FormRepositoryInterface&Stub $repository;
    private ForwardFactory&Stub $forwardFactory;
    private LoggerInterface&Stub $logger;
    private View $view;
    private Http&MockObject $request;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?LoggerInterface $logger = null, ?FormRepositoryInterface $repository = null): void
    {
        $this->pageFactory = $this->createStub(PageFactory::class);
        $this->repository = $repository ?? $this->createStub(FormRepositoryInterface::class);
        $this->forwardFactory = $this->createStub(ForwardFactory::class);
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $request = $this->createMock(Http::class);
        $this->request = $request;
        $request->method('getParam')->with('form_id')->willReturn('12');
        $request->method('getAlias')->with(Url::REWRITE_REQUEST_PATH_ALIAS)->willReturn('forms/contact');
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->view = new View(
            $this->pageFactory,
            $request,
            $this->repository,
            $storeManager,
            $this->forwardFactory,
            $this->logger,
            \Furan\FormBuilder\Test\Unit\Helper\TranslatorFactory::create($this->createStub(\Furan\FormBuilder\Model\Url\RedirectValidator::class)),
            $this->formUrls()
        );
    }

    private function expectNoroute(): Forward&MockObject
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $this->forwardFactory->method('create')->willReturn($forward);
        return $forward;
    }

    private function givenForm(array $settings): FormInterface&Stub
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact');
        $form->method('getSettings')->willReturn($settings);
        $this->repository->method('getActiveById')->with(12, 3)->willReturn($form);
        return $form;
    }

    public function testItRendersTheFormPageWithTheFormSetOnTheBlock(): void
    {
        $this->fixture(repository: $this->createMock(FormRepositoryInterface::class));
        $form = $this->givenForm(['page_title' => 'Get in touch', 'meta_description' => 'Write to us']);
        $block = new \Magento\Framework\DataObject();
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with('furanform.form')->willReturn($block);
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('set')->with('Get in touch');
        $config = $this->createMock(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $config->expects($this->once())->method('setDescription')->with('Write to us');
        $config->expects($this->once())->method('addBodyClass')->with('furanform-view');
        $page = $this->createStub(Page::class);
        $page->method('getLayout')->willReturn($layout);
        $page->method('getConfig')->willReturn($config);
        $this->pageFactory->method('create')->willReturn($page);

        $this->assertSame($page, $this->view->execute());
        $this->assertSame($form, $block->getForm());
    }

    public function testItForwardsToNorouteForAnUnknownOrInactiveForm(): void
    {
        $this->repository->method('getActiveById')->willThrowException(new NoSuchEntityException());
        $forward = $this->expectNoroute();

        $this->assertSame($forward, $this->view->execute());
    }

    public function testItForwardsToNorouteAndLogsWhenTheBlockIsMissing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger, repository: $this->createMock(FormRepositoryInterface::class));
        $this->givenForm([]);
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturn(false);
        $page = $this->createStub(Page::class);
        $page->method('getLayout')->willReturn($layout);
        $this->pageFactory->method('create')->willReturn($page);
        $logger->expects($this->once())->method('error');
        $forward = $this->expectNoroute();

        $this->assertSame($forward, $this->view->execute());
    }

    public function testItServesTheFormViewOnlyWhenReachedThroughTheRouter(): void
    {
        $this->fixture(repository: $this->createMock(FormRepositoryInterface::class));
        $this->givenForm([]);
        $block = new \Magento\Framework\DataObject();
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($block);
        $title = $this->createStub(Title::class);
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getLayout')->willReturn($layout);
        $page->method('getConfig')->willReturn($config);
        $this->pageFactory->method('create')->willReturn($page);

        $this->assertSame($page, $this->view->execute());
    }

    public function testItForwardsDirectViewRequestsWithoutTheRouterAliasToNoroute(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturn('12');
        $request->method('getAlias')->willReturn(null);
        $view = new View(
            $this->pageFactory,
            $request,
            $repository,
            $this->createStub(StoreManagerInterface::class),
            $this->forwardFactory,
            $this->logger,
            \Furan\FormBuilder\Test\Unit\Helper\TranslatorFactory::create($this->createStub(\Furan\FormBuilder\Model\Url\RedirectValidator::class)),
            $this->formUrls()
        );
        $repository->expects($this->never())->method('getActiveById');
        $forward = $this->expectNoroute();

        $this->assertSame($forward, $view->execute());
    }

    private function formUrls(): \Furan\FormBuilder\Model\Seo\FormUrls
    {
        $urls = $this->createStub(\Furan\FormBuilder\Model\Seo\FormUrls::class);
        $urls->method('url')->willReturn('https://shop.test/forms/contact');
        $urls->method('alternates')->willReturn([]);
        return $urls;
    }
}
