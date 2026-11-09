<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Form\Draft;
use Furan\FormBuilder\Model\Access\FormAccess;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Draft\DraftStore;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Psr\Log\LoggerInterface;

class DraftTest extends TestCase
{
    private FormRepositoryInterface&Stub $repository;
    private DraftStore&Stub $store;
    private Session&Stub $session;
    private Config&Stub $config;
    private Validator&Stub $formKeyValidator;
    private Http&Stub $request;
    private Json&Stub $json;
    private FormInterface&Stub $form;
    private Draft $controller;
    private array $params = [];
    private ?int $code = null;
    private mixed $data = null;

    private bool $impersonating = false;

    private function translator(): Translator
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('localize')->willReturnArgument(0);
        return $translator;
    }

    private function gate(): ScheduleGate
    {
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(1800000000);
        return new ScheduleGate($clock);
    }

    private function impersonation(): Impersonation
    {
        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturnCallback(fn (): bool => $this->impersonating);
        $impersonation->method('accountRefusal')->willReturn('Not while impersonating.');
        return $impersonation;
    }

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?Validator $formKeyValidator = null, ?DraftStore $store = null): void
    {
        $this->repository = $this->createStub(FormRepositoryInterface::class);
        $this->store = $store ?? $this->createStub(DraftStore::class);
        $this->session = $this->createStub(Session::class);
        $this->session->method('isLoggedIn')->willReturn(true);
        $this->session->method('getCustomerId')->willReturn(7);
        $this->session->method('getCustomerGroupId')->willReturn(1);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isDraftSyncEnabled')->willReturn(true);
        $this->formKeyValidator = $formKeyValidator ?? $this->createStub(Validator::class);
        $this->request = $this->createStub(Http::class);
        $this->request->method('getParam')->willReturnCallback(fn (string $k) => $this->params[$k] ?? null);
        $this->params = ['form_id' => '12'];
        $this->form = $this->createStub(FormInterface::class);
        $this->form->method('getSettings')->willReturn(['draft_mode' => 'local']);
        $this->form->method('getSchema')->willReturn(['pages' => []]);
        $this->repository->method('getActiveById')->willReturn($this->form);

        $this->json = $this->createStub(Json::class);
        $this->json->method('setHeader')->willReturnSelf();
        $this->json->method('setHttpResponseCode')->willReturnCallback(function (int $c) {
            $this->code = $c;
            return $this->json;
        });
        $this->json->method('setData')->willReturnCallback(function ($d) {
            $this->data = $d;
            return $this->json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->controller = new Draft(
            $jsonFactory,
            $this->request,
            $this->repository,
            $storeManager,
            $this->store,
            new FormAccess(),
            $this->session,
            $this->config,
            $this->formKeyValidator,
            new JsonSerializer(),
            $this->createStub(LoggerInterface::class),
            $this->impersonation(),
            $this->translator(),
            $this->gate()
        );
    }

    private function post(array $params): void
    {
        $this->request->method('isPost')->willReturn(true);
        $this->params += $params;
    }

    public function testItSavesADraftForALoggedInCustomer(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $this->post(['action' => 'save', 'values' => '{"first_name":"Ann"}']);
        $this->store->method('sanitize')->willReturn(['first_name' => 'Ann']);
        $store->expects($this->once())->method('save')->with(12, 7, 3, ['first_name' => 'Ann']);

        $this->controller->execute();

        $this->assertNull($this->code);
        $this->assertTrue($this->data['success']);
    }

    private function closedRepository(): FormRepositoryInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn(
            ['draft_mode' => 'local', 'close_at' => '2020-01-01T00:00:00Z', 'closed_message' => 'We are closed.']
        );
        $form->method('getSchema')->willReturn(['pages' => []]);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getActiveById')->willReturn($form);
        return $repository;
    }

    public function testItRefusesAServerDraftSaveWhileTheFormIsClosed(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $controller = $this->rebuild(repository: $this->closedRepository());
        $this->post(['action' => 'save', 'values' => '{"first_name":"Ann"}']);
        $store->expects($this->never())->method('sanitize');
        $store->expects($this->never())->method('save');

        $controller->execute();

        $this->assertSame(403, $this->code);
        $this->assertSame('We are closed.', $this->data['message']);
        $this->assertSame(['state' => 'closed', 'message' => 'We are closed.'], $this->data['schedule']);
    }

    public function testItDoesNotLocaliseAnOpenFormInTheTokenOrDraftEndpoints(): void
    {
        $translator = $this->createMock(Translator::class);
        $translator->expects($this->never())->method('localize');
        $controller = $this->rebuild(translator: $translator);
        $this->post(['action' => 'save', 'values' => '{"first_name":"Ann"}']);
        $this->store->method('sanitize')->willReturn(['first_name' => 'Ann']);

        $controller->execute();

        $this->assertTrue($this->data['success']);
    }

    public function testItStillClearsADraftWhileTheFormIsClosed(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $controller = $this->rebuild(repository: $this->closedRepository());
        $this->post(['action' => 'clear']);
        $store->expects($this->once())->method('clear')->with(12, 7, 3);

        $controller->execute();

        $this->assertTrue($this->data['success']);
    }

    public function testItRefusesToSaveOrClearDraftsWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $this->impersonating = true;
        $this->post(['action' => 'clear']);
        $store->expects($this->never())->method('save');
        $store->expects($this->never())->method('clear');

        $this->controller->execute();

        $this->assertSame(403, $this->code);
        $this->assertSame('Not while impersonating.', $this->data['message']);
    }

    public function testItRefusesDraftsFromGuests(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $session = $this->createStub(Session::class);
        $session->method('isLoggedIn')->willReturn(false);
        $controller = $this->rebuild(session: $session);
        $this->post(['action' => 'save', 'values' => '{}']);
        $store->expects($this->never())->method('save');
        $store->expects($this->never())->method('find');

        $controller->execute();

        $this->assertSame(403, $this->code);
    }

    public function testItRejectsADraftLargerThan64Kb(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $this->post(['action' => 'save', 'values' => json_encode(['first_name' => str_repeat('a', 65537)])]);
        $store->expects($this->never())->method('save');

        $this->controller->execute();

        $this->assertSame(413, $this->code);
    }

    public function testItRejectsADraftSaveWithoutAValidFormKeyEvenForAjaxRequests(): void
    {
        $formKeyValidator = $this->createMock(Validator::class);
        $this->fixture(formKeyValidator: $formKeyValidator);
        $this->request->method('isPost')->willReturn(true);
        $formKeyValidator->expects($this->once())->method('validate')->with($this->request)->willReturn(false);

        $this->assertFalse($this->controller->validateForCsrf($this->request));
        $this->assertNotNull($this->controller->createCsrfValidationException($this->request));
        $this->assertSame(403, $this->code);
    }

    public function testItRefusesADraftSaveFromANonHttpRequest(): void
    {
        $this->fixture();

        $this->assertFalse($this->controller->validateForCsrf($this->createStub(RequestInterface::class)));
    }

    public function testItRefusesDraftsForFormsTheCustomerMayNotAccess(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn(['draft_mode' => 'local', 'access_groups' => [5]]);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getActiveById')->willReturn($form);
        $controller = $this->rebuild(repository: $repository);
        $store->expects($this->never())->method('find');

        $controller->execute();

        $this->assertSame(403, $this->code);
    }

    public function testItLoadsADraftWithAGetRequestWithoutAFormKey(): void
    {
        $store = $this->createMock(DraftStore::class);
        $formKeyValidator = $this->createMock(Validator::class);
        $this->fixture(store: $store, formKeyValidator: $formKeyValidator);
        $this->request->method('isPost')->willReturn(false);
        $formKeyValidator->expects($this->never())->method('validate');
        $store->method('find')->with(12, 7, 3)
            ->willReturn(['values' => ['a' => 'b'], 'updatedAt' => 1700000000000]);

        $this->assertTrue($this->controller->validateForCsrf($this->request));
        $this->controller->execute();

        $this->assertNull($this->code);
        $this->assertSame(['values' => ['a' => 'b'], 'updatedAt' => 1700000000000], $this->data);
    }

    #[DataProvider('nonLocalModes')]
    public function testItRefusesServerDraftsUnlessTheFormsDraftModeIsLocal(string $mode): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn(['draft_mode' => $mode]);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getActiveById')->willReturn($form);
        $controller = $this->rebuild(repository: $repository);
        $this->post(['action' => 'save', 'values' => '{"a":"b"}']);
        $store->expects($this->never())->method('save');
        $store->expects($this->never())->method('find');

        $controller->execute();

        $this->assertSame(404, $this->code);
    }

    public static function nonLocalModes(): array
    {
        return [['session'], ['off']];
    }

    public function testItClearsADraft(): void
    {
        $store = $this->createMock(DraftStore::class);
        $this->fixture(store: $store);
        $this->post(['action' => 'clear']);
        $store->expects($this->once())->method('clear')->with(12, 7, 3);

        $this->controller->execute();

        $this->assertTrue($this->data['success']);
    }

    private function rebuild(
        ?Session $session = null,
        ?FormRepositoryInterface $repository = null,
        ?Translator $translator = null
    ): Draft {
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Draft(
            $jsonFactory,
            $this->request,
            $repository ?? $this->repository,
            $storeManager,
            $this->store,
            new FormAccess(),
            $session ?? $this->session,
            $this->config,
            $this->formKeyValidator,
            new JsonSerializer(),
            $this->createStub(LoggerInterface::class),
            $this->impersonation(),
            $translator ?? $this->translator(),
            $this->gate()
        );
    }
}
