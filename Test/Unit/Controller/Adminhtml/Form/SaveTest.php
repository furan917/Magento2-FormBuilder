<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormManagementInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Form\Save;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Api\FormWriteContext;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\UnsupportedSchemaVersionException;
use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;

class SaveTest extends ControllerTestCase
{
    private FormRepositoryInterface&Stub $repository;
    private FormManagementInterface&Stub $management;
    private DataPersistorInterface&Stub $persistor;
    private ReCaptcha&Stub $reCaptcha;
    private AuthorizationInterface&Stub $auth;
    private \Magento\Backend\Model\Auth&Stub $backendAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createStub(FormRepositoryInterface::class);
        $this->management = $this->createStub(FormManagementInterface::class);
        $this->persistor = $this->createStub(DataPersistorInterface::class);
        $this->reCaptcha = $this->createStub(ReCaptcha::class);
        $this->auth = $this->createStub(AuthorizationInterface::class);
        $this->backendAuth = $this->createStub(\Magento\Backend\Model\Auth::class);
        $this->context->method('getAuthorization')->willReturn($this->auth);
        $this->context->method('getAuth')->willReturn($this->backendAuth);
    }

    private function post(array $overrides = []): array
    {
        return $overrides + [
            'form_id' => '', 'identifier' => 'contact', 'title' => 'Contact', 'is_active' => '1',
            'store_ids' => '[0]', 'schema' => '{"version":1,"pages":[]}', 'settings' => '{"submit_label":"Go"}', 'back' => '',
        ];
    }

    private function controller(array $post): Save
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $post[$key] ?? $default
        );
        return new Save(
            $this->context,
            $this->repository,
            $this->management,
            $this->persistor,
            new Json(),
            $this->createStub(LoggerInterface::class),
            $this->reCaptcha,
            $this->storeManager(),
            new \Furan\FormBuilder\Model\Access\FormAccess()
        );
    }

    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ([1, 2] as $id) {
            $website = $this->createStub(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[$id] = $website;
        }
        $stores = [];
        foreach ([1 => 1, 3 => 2] as $storeId => $websiteId) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[$storeId] = $store;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getWebsites')->willReturn($websites);
        $manager->method('getStore')->willReturnCallback(
            static fn (int $id): StoreInterface => $stores[$id] ?? throw new NoSuchEntityException()
        );
        return $manager;
    }

    private function savedForm(int $id = 11, array $settings = [], array $storeIds = [0]): Form&Stub
    {
        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn($id);
        $form->method('getSettings')->willReturn($settings);
        $form->method('getStoreIds')->willReturn($storeIds);
        return $form;
    }

    public function testItRequiresTheSaveFormsAclResource(): void
    {
        $this->assertSame('Furan_FormBuilder::forms_save', Save::ADMIN_RESOURCE);
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller([]));
    }

    public function testItHandsTheDecodedRequestToTheFormManagementAndRedirectsBackWhenRequested(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $saved = $this->savedForm();
        $this->management->expects($this->once())->method('save')->with(
            null,
            [
                'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true, 'store_ids' => [0],
                'schema' => ['version' => 1, 'pages' => []], 'settings' => ['submit_label' => 'Go'],
            ],
            $this->anything()
        )->willReturn($saved);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')
            ->with($this->callback(static fn ($m) => (string)$m === 'The form has been saved.'));
        $this->expectingMessages()->expects($this->never())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 11])->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller($this->post(['back' => 'edit']))->execute());
    }

    public function testItPassesTheTranslationsOnlyWhenTheyArePosted(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(
            null,
            $this->callback(static fn (array $d): bool => $d['translations'] === ['2' => ['form' => []]]),
            $this->anything()
        )->willReturn($this->savedForm());
        $this->redirect->method('setPath')->willReturnSelf();

        $this->controller($this->post(['translations' => '{"2":{"form":[]}}']))->execute();
    }

    public function testItRefusesASaveWhoseTranslationsAreNotValidJson(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->never())->method('save');
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        $this->persistor->expects($this->once())->method('set')->with(
            'furan_formbuilder_form',
            $this->callback(static fn (array $d): bool => $d['translations'] === '{not json'
                && $d['errors'] === ['The form data could not be read.'])
        );
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/new')->willReturnSelf();

        $this->controller($this->post(['translations' => '{not json']))->execute();
    }

    public function testItBuildsTheWriteContextFromTheAdminsPermissionsAndId(): void
    {
        $this->auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => $r === 'Furan_FormBuilder::submissions');
        $user = $this->createStub(\Magento\User\Model\User::class);
        $user->method('getId')->willReturn('7');
        $this->backendAuth->method('getUser')->willReturn($user);
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(
            $this->anything(),
            $this->anything(),
            $this->callback(static fn (FormWriteContext $c): bool => $c->adminId === 7
                && $c->isAllowed('Furan_FormBuilder::submissions')
                && !$c->isAllowed('Furan_FormBuilder::webhooks_personal'))
        )->willReturn($this->savedForm());
        $this->redirect->method('setPath')->willReturnSelf();

        $this->controller($this->post())->execute();
    }

    public function testItRejectsInvalidJsonAndPersistsThePostedData(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->never())->method('save');
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        $this->persistor->expects($this->once())->method('set')->with(
            'furan_formbuilder_form',
            $this->callback(static fn (array $d): bool => $d['schema'] === '{not json'
                && $d['identifier'] === 'contact'
                && $d['errors'] === ['The form data could not be read.'])
        );
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(static fn ($m) => (string)$m === 'The form data could not be read.'));
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/new')->willReturnSelf();

        $this->controller($this->post(['schema' => '{not json']))->execute();
    }

    public function testItReportsEveryWriterMessageAndPersistsThePostedData(): void
    {
        $existing = $this->createStub(Form::class);
        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->method('getById')->with(5)->willReturn($existing);
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with($existing)
            ->willThrowException(new InvalidSchemaException(['Bad label', 'Bad name']));
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        $this->persistor->expects($this->once())->method('set')->with(
            'furan_formbuilder_form',
            $this->callback(static fn (array $d): bool => $d['errors'] === ['Bad label', 'Bad name'])
        );
        $this->expectingMessages()->expects($this->exactly(2))->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 5])->willReturnSelf();

        $this->controller($this->post(['form_id' => '5']))->execute();
    }

    public function testItRedirectsToTheGridWhenThePostedFormIdDoesNotExist(): void
    {
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException(__('nope')));
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->never())->method('save');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $this->controller($this->post(['form_id' => '99']))->execute();
    }

    public function testItDoesNotPersistThePostedDataWhenTheStoredFormHasANewerSchema(): void
    {
        $this->repository->method('getById')->willReturn($this->createStub(FormInterface::class));
        $this->management->method('save')->willThrowException(new UnsupportedSchemaVersionException(99, 'Too new.'));
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        $this->persistor->expects($this->never())->method('set');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')->with('Too new.');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 11])->willReturnSelf();

        $this->controller($this->post(['form_id' => '11']))->execute();
    }

    public function testItWarnsWhenAnAutoresponderFormOnAllStoreViewsIsSavedWithoutReCaptchaOnEveryWebsite(): void
    {
        foreach ([[[1 => true, 2 => false], 1], [[1 => true, 2 => true], 0]] as [$captcha, $warnings]) {
            $this->setUp();
            $this->management->method('save')->willReturn($this->savedForm(11, ['autoresponder_enabled' => true]));
            $this->redirect->method('setPath')->willReturnSelf();
            $this->reCaptcha->method('isEnabledForWebsite')->willReturnCallback(static fn (int $w): bool => $captcha[$w]);
            $this->expectingMessages()->expects($this->exactly($warnings))->method('addWarningMessage');

            $this->controller($this->post(['back' => 'edit']))->execute();
        }
    }

    public function testItDoesNotWarnAboutReCaptchaWhenTheFormRequiresLogin(): void
    {
        $this->management->method('save')
            ->willReturn($this->savedForm(11, ['autoresponder_enabled' => true, 'access_login_required' => true]));
        $this->redirect->method('setPath')->willReturnSelf();
        $this->reCaptcha->method('isEnabledForWebsite')->willReturn(false);
        $this->expectingMessages()->expects($this->never())->method('addWarningMessage');

        $this->controller($this->post(['back' => 'edit']))->execute();
    }

    public function testItDoesNotWarnAboutReCaptchaWhenTheCustomerGroupsLeaveGuestsOut(): void
    {
        foreach ([[['1', '2'], 0], [['0', '1'], 1]] as [$groups, $warnings]) {
            $this->setUp();
            $this->management->method('save')->willReturn($this->savedForm(11, ['autoresponder_enabled' => true, 'access_groups' => $groups]));
            $this->redirect->method('setPath')->willReturnSelf();
            $this->reCaptcha->method('isEnabledForWebsite')->willReturn(false);
            $this->expectingMessages()->expects($this->exactly($warnings))->method('addWarningMessage');

            $this->controller($this->post(['back' => 'edit']))->execute();
        }
    }

    public function testItChecksReCaptchaOnlyForTheWebsitesOfTheFormsStoreViews(): void
    {
        $reCaptcha = $this->reCaptcha = $this->createMock(ReCaptcha::class);
        $this->management->method('save')
            ->willReturn($this->savedForm(11, ['autoresponder_enabled' => true], [3, 99]));
        $this->redirect->method('setPath')->willReturnSelf();
        $reCaptcha->expects($this->once())->method('isEnabledForWebsite')->with(2)->willReturn(true);
        $this->expectingMessages()->expects($this->never())->method('addWarningMessage');

        $this->controller($this->post(['back' => 'edit', 'store_ids' => '[3,99]']))->execute();
    }
}
