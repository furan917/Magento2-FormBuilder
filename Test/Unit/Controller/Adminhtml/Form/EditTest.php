<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Form\Edit;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;

class EditTest extends ControllerTestCase
{
    private bool $canSave = true;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(
            fn ($r) => $r !== 'Furan_FormBuilder::forms_save' || $this->canSave
        );
        $this->context->method('getAuthorization')->willReturn($auth);
    }

    public function testItRequiresTheManageFormsAclResource(): void
    {
        $this->assertSame('Furan_FormBuilder::forms', Edit::ADMIN_RESOURCE);
        $this->assertInstanceOf(
            HttpGetActionInterface::class,
            new Edit($this->context, $this->createStub(PageFactory::class), $this->createStub(FormRepositoryInterface::class))
        );
    }

    public function testItRedirectsToTheGridWhenEditingAnUnknownForm(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('99');
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(99)->willThrowException(new NoSuchEntityException(__('nope')));
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $controller = new Edit($this->context, $this->createStub(PageFactory::class), $repository);

        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testItTitlesTheEditAndNewPages(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact');
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(3)->willReturn($form);

        $this->expectingRequest()->method('getParam')->with('id')->willReturnOnConsecutiveCalls('3', null);
        $titles = [];
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(static function ($t) use (&$titles): void {
            $titles[] = (string)$t;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($config);
        $page->method('setActiveMenu')->with('Furan_FormBuilder::form_list');
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        $controller = new Edit($this->context, $factory, $repository);

        $this->assertSame($page, $controller->execute());
        $this->assertSame($page, $controller->execute());
        $this->assertSame(['Edit Form: Contact', 'New Form'], $titles);
    }

    public function testWithoutSaveRightsTheBuilderIsAViewAndNewFormsAreRefused(): void
    {
        $this->canSave = false;
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact');
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(3)->willReturn($form);
        $this->expectingRequest()->method('getParam')->with('id')->willReturnOnConsecutiveCalls('3', null);
        $titles = [];
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(static function ($t) use (&$titles): void {
            $titles[] = (string)$t;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();
        $controller = new Edit($this->context, $factory, $repository);

        $this->assertSame($page, $controller->execute());
        $this->assertSame($this->redirect, $controller->execute());
        $this->assertSame(['View Form: Contact'], $titles);
    }
}
