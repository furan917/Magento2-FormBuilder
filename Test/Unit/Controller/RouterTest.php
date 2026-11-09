<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Router;
use Furan\FormBuilder\Model\Config;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private ActionFactory&Stub $actionFactory;
    private FormRepositoryInterface&Stub $repository;
    private Config&Stub $config;
    private PageResource&Stub $pageResource;
    private Router $router;
    private bool $formsEnabled = true;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?ActionFactory $actionFactory = null, ?PageResource $pageResource = null, ?FormRepositoryInterface $repository = null): void
    {
        $this->actionFactory = $actionFactory ?? $this->createStub(ActionFactory::class);
        $this->repository = $repository ?? $this->createStub(FormRepositoryInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getRoutePrefix')->willReturn('forms');
        $this->config->method('isEnabled')->willReturnCallback(fn (): bool => $this->formsEnabled);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->pageResource = $pageResource ?? $this->createStub(PageResource::class);
        $this->router = new Router(
            $this->actionFactory,
            $this->repository,
            $storeManager,
            $this->config,
            $this->pageResource
        );
    }

    private function request(string $path, string $module = ''): Http&Stub
    {
        return $this->configureRequest($this->createStub(Http::class), $path, $module);
    }

    private function assertedRequest(string $path, string $module = ''): Http&MockObject
    {
        return $this->configureRequest($this->createMock(Http::class), $path, $module);
    }

    /**
     * @template T of Http&Stub
     * @param T $request
     * @return T
     */
    private function configureRequest(Http&Stub $request, string $path, string $module = ''): Http&Stub
    {
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getModuleName')->willReturn($module);
        return $request;
    }

    public function testItMatchesThePrefixAndIdentifierAndForwardsToTheViewAction(): void
    {
        $actionFactory = $this->createMock(ActionFactory::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(actionFactory: $actionFactory, repository: $repository);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(12);
        $repository->expects($this->once())->method('getActiveByIdentifier')
            ->with('contact-us', 3)->willReturn($form);
        $request = $this->assertedRequest('/forms/contact-us/');
        $request->expects($this->once())->method('setModuleName')->with('furanform')->willReturnSelf();
        $request->expects($this->once())->method('setControllerName')->with('form')->willReturnSelf();
        $request->expects($this->once())->method('setActionName')->with('view')->willReturnSelf();
        $request->expects($this->once())->method('setParam')->with('form_id', 12)->willReturnSelf();
        $request->expects($this->once())->method('setAlias')
            ->with(Url::REWRITE_REQUEST_PATH_ALIAS, 'forms/contact-us');
        $action = $this->createStub(ActionInterface::class);
        $actionFactory->expects($this->once())->method('create')->with(Forward::class)->willReturn($action);

        $this->assertSame($action, $this->router->match($request));
    }

    public function testItNoLongerRoutesAFormIdentifierWithATrailingNewline(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $repository->expects($this->never())->method('getActiveByIdentifier');
        $this->assertNull($this->router->match($this->request("/forms/contact\n")));
    }

    public function testItReturnsNullForPathsOutsideThePrefix(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $repository->expects($this->never())->method('getActiveByIdentifier');
        $this->assertNull($this->router->match($this->request('/other/contact-us')));
        $this->assertNull($this->router->match($this->request('/forms')));
        $this->assertNull($this->router->match($this->request('/forms/a/b')));
        $this->assertNull($this->router->match($this->request('/forms/Bad_Id')));
    }

    public function testItForwardsAMatchedPrefixWithAnUnknownIdentifierToTheUncachedNotFoundAction(): void
    {
        $actionFactory = $this->createMock(ActionFactory::class);
        $this->fixture(actionFactory: $actionFactory);
        $this->repository->method('getActiveByIdentifier')->willThrowException(new NoSuchEntityException());
        $request = $this->assertedRequest('/forms/missing');
        $request->expects($this->once())->method('setModuleName')->with('furanform')->willReturnSelf();
        $request->expects($this->once())->method('setControllerName')->with('form')->willReturnSelf();
        $request->expects($this->once())->method('setActionName')->with('notFound')->willReturnSelf();
        $request->expects($this->never())->method('setAlias');
        $action = $this->createStub(ActionInterface::class);
        $actionFactory->expects($this->once())->method('create')->with(Forward::class)->willReturn($action);

        $this->assertSame($action, $this->router->match($request));
    }

    public function testItLetsTheCmsRouterHandleACmsPageUnderTheFormPrefix(): void
    {
        $actionFactory = $this->createMock(ActionFactory::class);
        $pageResource = $this->createMock(PageResource::class);
        $this->fixture(actionFactory: $actionFactory, pageResource: $pageResource);
        $this->repository->method('getActiveByIdentifier')->willThrowException(new NoSuchEntityException());
        $pageResource->expects($this->once())->method('checkIdentifier')
            ->with('forms/about', 3)->willReturn('4');
        $request = $this->assertedRequest('/forms/about');
        $request->expects($this->never())->method('setActionName');
        $actionFactory->expects($this->never())->method('create');

        $this->assertNull($this->router->match($request));
    }

    public function testItDoesNotMatchRequestsAlreadyRoutedToTheModule(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $repository->expects($this->never())->method('getActiveByIdentifier');
        $this->assertNull($this->router->match($this->request('/forms/contact-us', 'furanform')));
    }

    public function testItLeavesFormUrlsToTheNotFoundPageWhenFormsAreDisabled(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->formsEnabled = false;
        $repository->expects($this->never())->method('getActiveByIdentifier');
        $this->assertNull($this->router->match($this->request('/forms/contact-us')));
    }
}
