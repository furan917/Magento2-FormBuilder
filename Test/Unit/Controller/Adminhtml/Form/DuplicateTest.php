<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\FormManagementInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Form\Duplicate;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\Form\Identifier;
use Furan\FormBuilder\Api\FormWriteContext;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Test\Unit\Helper\Translated;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\AuthorizationInterface;
use Psr\Log\LoggerInterface;

class DuplicateTest extends ControllerTestCase
{
    private function source(array $schema = ['version' => 1, 'pages' => []]): Form
    {
        $source = $this->createStub(Form::class);
        $source->method('getIdentifier')->willReturn('contact');
        $source->method('getTitle')->willReturn('Contact');
        $source->method('getSchema')->willReturn($schema);
        $source->method('getSettings')->willReturn(['submit_label' => 'Go']);
        $source->method('getStoreIds')->willReturn([0, 2]);
        $source->method('getTranslations')->willReturn([2 => ['form' => ['submit_label' => ['t' => 'Aller', 's' => 'Go']]]]);
        return $source;
    }

    private function controller(FormRepositoryInterface $repository, FormManagementInterface $management, ?FormResource $resource = null, ?\Magento\Backend\Model\Auth $backendAuth = null): Duplicate
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => $r !== 'Furan_FormBuilder::submissions');
        $this->context->method('getAuthorization')->willReturn($auth);
        $this->context->method('getAuth')->willReturn($backendAuth ?? $this->createStub(\Magento\Backend\Model\Auth::class));

        return new Duplicate(
            $this->context,
            $repository,
            $management,
            $resource ?? $this->createStub(FormResource::class),
            new SchemaMigrator($this->createStub(LoggerInterface::class))
        );
    }

    public function testItDuplicatesAFormAsAnInactiveCopyWithAUniqueIdentifier(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('3');
        $source = $this->source();
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($source);
        $copy = $this->createStub(Form::class);
        $copy->method('getId')->willReturn(42);
        $management = $this->createMock(FormManagementInterface::class);
        $management->expects($this->once())->method('save')->with(
            null,
            [
                'identifier' => 'contact-copy-3', 'title' => 'Contact (copy)', 'is_active' => false, 'store_ids' => [0, 2],
                'schema' => ['version' => 1, 'pages' => []], 'settings' => ['submit_label' => 'Go'],
                'translations' => [2 => ['form' => ['submit_label' => ['t' => 'Aller', 's' => 'Go']]]],
            ],
            $this->anything(),
            $source
        )->willReturn($copy);
        $resource = $this->createStub(FormResource::class);
        $resource->method('getIdByIdentifier')->willReturnMap([
            ['contact-copy', 5],
            ['contact-copy-2', 6],
            ['contact-copy-3', null],
        ]);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        // The copy opens in the builder straight away.
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 42])->willReturnSelf();

        $controller = $this->controller($repository, $management, $resource);

        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testItTranslatesTheDuplicateSuffix(): void
    {
        $this->request->method('getParam')->willReturn('3');
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->source());
        $management = $this->createMock(FormManagementInterface::class);
        $management->expects($this->once())->method('save')->with(
            $this->anything(),
            $this->callback(static fn (array $d): bool => $d['title'] === 'Contact T:(copy)'),
            $this->anything(),
            $this->anything()
        )->willReturn($this->createStub(Form::class));
        $this->redirect->method('setPath')->willReturnSelf();

        Translated::with('T:', fn () => $this->controller($repository, $management)->execute());
    }

    public function testItFitsAMultibyteTranslatedCopySuffixWithinTheTitleLimit(): void
    {
        $this->request->method('getParam')->willReturn('3');
        $source = $this->createStub(Form::class);
        $source->method('getIdentifier')->willReturn('contact');
        $source->method('getTitle')->willReturn(str_repeat('é', Identifier::MAX_TITLE_LENGTH));
        $source->method('getSchema')->willReturn(['version' => 1, 'pages' => []]);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($source);
        $management = $this->createMock(FormManagementInterface::class);
        $management->expects($this->once())->method('save')->with(
            $this->anything(),
            $this->callback(static fn (array $d): bool => mb_strlen($d['title']) === Identifier::MAX_TITLE_LENGTH
                && str_ends_with($d['title'], ' 日本(copy)')),
            $this->anything(),
            $this->anything()
        )->willReturn($this->createStub(Form::class));
        $this->redirect->method('setPath')->willReturnSelf();

        Translated::with('日本', fn () => $this->controller($repository, $management)->execute());
    }

    public function testItGivesTheWriterTheAdminsPermissions(): void
    {
        $this->request->method('getParam')->willReturn('3');
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->source());
        $management = $this->createMock(FormManagementInterface::class);
        $management->expects($this->once())->method('save')->with(
            $this->anything(),
            $this->anything(),
            $this->callback(static fn (FormWriteContext $c): bool => !$c->isAllowed('Furan_FormBuilder::submissions')
                && $c->isAllowed('Furan_FormBuilder::forms_save')),
            $this->anything()
        )->willReturn($this->createStub(Form::class));
        $this->redirect->method('setPath')->willReturnSelf();

        $this->controller($repository, $management)->execute();
    }

    public function testItPassesTheAdminIdWhenDuplicatingAForm(): void
    {
        $this->request->method('getParam')->willReturn('3');
        $user = $this->createStub(\Magento\User\Model\User::class);
        $user->method('getId')->willReturn('7');
        $backendAuth = $this->createStub(\Magento\Backend\Model\Auth::class);
        $backendAuth->method('getUser')->willReturn($user);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->source());
        $management = $this->createMock(FormManagementInterface::class);
        $management->expects($this->once())->method('save')->with(
            $this->anything(),
            $this->anything(),
            $this->callback(static fn (FormWriteContext $c): bool => $c->adminId === 7),
            $this->anything()
        )->willReturn($this->createStub(Form::class));
        $this->redirect->method('setPath')->willReturnSelf();

        $this->controller($repository, $management, null, $backendAuth)->execute();
    }

    public function testItShowsEveryWriterMessageAndGoesBackToTheGrid(): void
    {
        $this->request->method('getParam')->willReturn('3');
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->source());
        $management = $this->createStub(FormManagementInterface::class);
        $management->method('save')->willThrowException(new InvalidSchemaException(['One', 'Two']));
        $this->expectingMessages()->expects($this->exactly(2))->method('addErrorMessage');
        $this->expectingMessages()->expects($this->never())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $this->controller($repository, $management)->execute();
    }

    public function testItRefusesToDuplicateAFormWhoseStoredSchemaIsNewerThanSupported(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('3');
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->source(['version' => 9]));
        $management = $this->createMock(FormManagementInterface::class);
        $management->expects($this->never())->method('save');
        $this->expectingMessages()->expects($this->never())->method('addSuccessMessage');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller($repository, $management)->execute());
    }
}
