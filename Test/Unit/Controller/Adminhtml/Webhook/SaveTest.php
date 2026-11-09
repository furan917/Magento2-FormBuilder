<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Controller\Adminhtml\Webhook\Delete;
use Furan\FormBuilder\Controller\Adminhtml\Webhook\Edit;
use Furan\FormBuilder\Controller\Adminhtml\Webhook\Save;
use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Webhook\EndpointAdmin;
use Furan\FormBuilder\Model\Webhook\EndpointInputException;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Test\Unit\Model\Webhook\WebhookFixtures;
use Magento\Framework\Exception\LocalizedException;
use Furan\FormBuilder\Model\Webhook\EndpointSecret;
use Furan\FormBuilder\Model\Webhook\SecretStash;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;

class SaveTest extends ControllerTestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private EndpointAdmin $admin;
    private SecretStash $stash;
    private DataPersistorInterface $persistor;

    /** @param array<string, mixed> $post */
    private function save(array $post, bool $personal = true, ?EndpointView $stored = null): Save
    {
        $request = $this->createStub(\Magento\Framework\App\Request\Http::class);
        $request->method('getPostValue')->willReturn($post);
        $context = $this->createStub(\Magento\Backend\App\Action\Context::class);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturn($personal);
        $context->method('getAuth')->willReturn($this->createStub(\Magento\Backend\Model\Auth::class));
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuthorization')->willReturn($auth);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $factory = $this->createStub(\Magento\Backend\Model\View\Result\RedirectFactory::class);
        $factory->method('create')->willReturn($this->redirect);
        $context->method('getResultRedirectFactory')->willReturn($factory);
        $this->admin = $this->double('admin', EndpointAdmin::class);
        $this->stash = $this->double('stash', SecretStash::class);
        $this->persistor = $this->double('persistor', DataPersistorInterface::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $repository = $this->createStub(EndpointRepository::class);
        $repository->method('getView')->willReturnCallback(static fn () => $stored ?? throw new \Magento\Framework\Exception\NoSuchEntityException());

        return new Save($context, $this->admin, $this->stash, $this->persistor, $repository);
    }

    private function stored(bool $personal = false): EndpointView
    {
        $fx = new WebhookFixtures($this->createStub(...));
        $fx->rows = [WebhookFixtures::row(['include_personal' => $personal ? '1' : '0'])];

        return $fx->repository->getView(7);
    }

    /** @return array<string, mixed> */
    private function failedSave(array $post, bool $personal = true, ?EndpointView $stored = null, ?\Throwable $error = null): array
    {
        $controller = $this->save($post, $personal, $stored);
        $this->admin->method('save')->willThrowException($error ?? new LocalizedException(__('Nope.')));
        $persisted = [];
        $this->persistor->method('set')->willReturnCallback(function (string $key, array $data) use (&$persisted) {
            $persisted = [$key, $data];
        });
        $controller->execute();

        return $persisted;
    }

    public function testItShowsTheSecretOnceAndNeverAgain(): void
    {
        $this->asserted = ['admin', 'stash'];
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 9]);
        $controller = $this->save(['name' => 'CRM', 'url' => 'https://a.example.com/x', 'fields' => "email, phone\nname"]);
        $this->admin->expects($this->once())->method('save')
            ->with($this->callback(static fn (array $d): bool => $d['fields'] === ['email', 'phone', 'name']), null, true)
            ->willReturn(new EndpointSecret(9, 'whsec_secretvalue'));
        $this->stash->expects($this->once())->method('put')->with(9, 'whsec_secretvalue');
        $messages = [];
        $this->messageManager->method('addSuccessMessage')->willReturnCallback(
            function ($m) use (&$messages) {
                $messages[] = (string)$m;
                return $this->messageManager;
            }
        );

        $controller->execute();

        $this->assertStringNotContainsString('whsec_secretvalue', implode(' ', $messages));
    }

    public function testItKeepsOnlyNonSecretFieldsAfterAFailedSave(): void
    {
        $this->asserted = ['stash', 'persistor'];
        $this->expectingMessages()->expects($this->atLeastOnce())->method('addErrorMessage');
        $controller = $this->save(['name' => 'CRM', 'url' => 'https://a.example.com/x?token=abc#f']);
        $this->admin->method('save')->willThrowException(new UnsafeUrlException('address_not_public'));
        $this->persistor->expects($this->once())->method('set')->with(
            \Furan\FormBuilder\Model\Admin\PersistKeys::webhook(null),
            $this->callback(static fn (array $d): bool => $d['url'] === 'https://a.example.com' && $d['name'] === 'CRM')
        );
        $this->stash->expects($this->never())->method('put');

        $controller->execute();
    }

    public function testItTakesTheScopeIdsOfTheChosenScopeType(): void
    {
        $this->asserted = ['admin'];
        $controller = $this->save(['name' => 'CRM', 'scope_type' => 'website', 'website_ids' => ['1'], 'store_ids' => ['5', '6']]);
        $this->admin->expects($this->once())->method('save')
            ->with($this->callback(static fn (array $d): bool => $d['scope_ids'] === ['1'] && $d['scope_type'] === 'website'))
            ->willReturn(null);

        $controller->execute();
    }

    public function testItPassesThePersonalPermissionToTheSave(): void
    {
        $this->asserted = ['admin'];
        $controller = $this->save(['name' => 'CRM', 'id' => '4'], false);
        $this->admin->expects($this->once())->method('save')->with($this->anything(), 4, false)->willReturn(null);

        $controller->execute();
    }

    public function testItSendsNoStoreOnTheEditPageAndSoftDeletes(): void
    {
        $title = $this->createStub(Title::class);
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $headers = [];
        $page->method('setHeader')->willReturnCallback(function (string $n, string $v) use (&$headers, $page) {
            $headers[$n] = $v;
            return $page;
        });
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturn(true);
        $this->context->method('getAuthorization')->willReturn($auth);
        (new Edit($this->context, $factory, $this->createStub(EndpointRepository::class)))->execute();
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);

        $admin = $this->createMock(EndpointAdmin::class);
        $admin->expects($this->once())->method('delete')->with(5);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getPost')->with('id')->willReturn('5');
        $context = $this->createStub(\Magento\Backend\App\Action\Context::class);
        $context->method('getAuth')->willReturn($this->createStub(\Magento\Backend\Model\Auth::class));
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $rf = $this->createStub(\Magento\Backend\Model\View\Result\RedirectFactory::class);
        $rf->method('create')->willReturn($this->redirect);
        $context->method('getResultRedirectFactory')->willReturn($rf);
        $this->redirect->method('setPath')->willReturnSelf();
        (new Delete($context, $admin))->execute();
    }

    public function testItKeepsFailedSaveValuesPerEndpoint(): void
    {
        $this->assertSame(\Furan\FormBuilder\Model\Admin\PersistKeys::webhook(null), $this->failedSave(['name' => 'A'])[0]);
        $this->assertSame(\Furan\FormBuilder\Model\Admin\PersistKeys::webhook(4), $this->failedSave(['name' => 'A', 'id' => '4'])[0]);
    }

    public function testItKeepsTheRawFieldsTextAfterAFailedSave(): void
    {
        $persisted = $this->failedSave(['name' => 'A', 'fields' => "email,  phone\nname"]);

        $this->assertSame("email,  phone\nname", $persisted[1]['fields']);
    }

    public function testItKeepsOnlyTheSchemeAndHostOfTheUrlAfterAFailedSave(): void
    {
        $persisted = $this->failedSave(['name' => 'A', 'url' => 'https://user:pw@hooks.slack.com:8443/services/T0/B0/xyz?a=b']);

        $this->assertSame('https://hooks.slack.com:8443', $persisted[1]['url']);
    }

    public function testItTellsTheAdminToReEnterTheFullUrlAfterAFailedSave(): void
    {
        $messages = [];
        $this->messageManager = $this->double('messageManager', \Magento\Framework\Message\ManagerInterface::class);
        $this->messageManager->method('addErrorMessage')->willReturnCallback(function ($m) use (&$messages) {
            $messages[] = (string)$m;
            return $this->messageManager;
        });
        $this->failedSave(['name' => 'A', 'url' => 'https://hooks.slack.com/services/x']);

        $this->assertContains('For security, only the start of the URL was kept. Enter the full URL again.', $messages);
    }

    public function testItKeepsTheStoredUrlWhenTheAdminDidNotChangeIt(): void
    {
        $messages = [];
        $this->messageManager = $this->double('messageManager', \Magento\Framework\Message\ManagerInterface::class);
        $this->messageManager->method('addErrorMessage')->willReturnCallback(function ($m) use (&$messages) {
            $messages[] = (string)$m;
            return $this->messageManager;
        });
        $persisted = $this->failedSave(
            ['name' => 'A', 'id' => '7', 'url' => 'https://crm.example.com/hooks/in'],
            true,
            $this->stored()
        );

        $this->assertArrayNotHasKey('url', $persisted[1]);
        $this->assertSame('A', $persisted[1]['name']);
        $this->assertSame(['Nope.'], $messages);
    }

    public function testItUsesTheStoredReceiverForALockedEndpointAfterAFailedSave(): void
    {
        $persisted = $this->failedSave(
            [
                'name' => 'A', 'id' => '7', 'url' => 'https://evil.example.com/x', 'scope_type' => 'website',
                'form_ids' => ['9'], 'field_mode' => 'selected', 'fields' => 'ssn', 'events' => ['submission.updated'],
                'all_forms' => '1',
            ],
            false,
            $this->stored(true)
        );

        $this->assertSame('A', $persisted[1]['name']);
        foreach (['url', 'scope_type', 'scope_ids', 'all_forms', 'form_ids', 'field_mode', 'fields', 'events'] as $key) {
            $this->assertArrayNotHasKey($key, $persisted[1]);
        }
    }

    public function testItPersistsTheErrorForEachFieldAfterAFailedSave(): void
    {
        $messages = [];
        $this->messageManager = $this->double('messageManager', \Magento\Framework\Message\ManagerInterface::class);
        $this->messageManager->method('addErrorMessage')->willReturnCallback(function ($m) use (&$messages) {
            $messages[] = (string)$m;
            return $this->messageManager;
        });
        $persisted = $this->failedSave(['name' => ''], true, null, new EndpointInputException(['name' => 'Name needed.', 'url' => 'Bad url.']));

        $this->assertSame(['name' => 'Name needed.', 'url' => 'Bad url.'], $persisted[1]['field_errors']);
        $this->assertSame(['Name needed.', 'Bad url.'], $messages);
    }

    public function testItRedirectsAViewOnlyAdminAwayFromANewEndpoint(): void
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => $r === 'Furan_FormBuilder::webhooks');
        $this->context->method('getAuthorization')->willReturn($auth);
        $factory = $this->createMock(PageFactory::class);
        $factory->expects($this->never())->method('create');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $result = (new Edit($this->context, $factory, $this->createStub(EndpointRepository::class)))->execute();

        $this->assertSame($this->redirect, $result);
    }

    public function testItDecidesManageAccessForANewEndpointThroughTheSharedAccessHelper(): void
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturn(true);
        $this->context->method('getAuthorization')->willReturn($auth);
        $page = $this->createStub(\Magento\Backend\Model\View\Result\Page::class);
        $page->method('getConfig')->willReturn($this->createStub(\Magento\Framework\View\Page\Config::class));
        $page->getConfig()->method('getTitle')->willReturn($this->createStub(\Magento\Framework\View\Page\Title::class));
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        $this->expectingRedirect()->expects($this->never())->method('setPath');

        $result = (new Edit($this->context, $factory, $this->createStub(EndpointRepository::class)))->execute();

        $this->assertSame($page, $result);
        $this->assertFalse((new \ReflectionClass(Edit::class))->hasMethod('mayManage'));
    }
}
