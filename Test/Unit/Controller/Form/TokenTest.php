<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Form\Token;
use Furan\FormBuilder\Model\Condition\Today;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Prefill\TokenPayload;
use Furan\FormBuilder\Model\Spam\TokenService;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private FormRepositoryInterface&Stub $repository;
    private TokenService&Stub $tokenService;
    private TokenPayload&Stub $payload;
    private Json&Stub $json;
    private Token $controller;
    private mixed $edit = null;
    private bool $impersonating = false;
    private ?FormInterface $localized = null;
    private Translator&Stub $translator;

    protected function setUp(): void
    {
        $this->build();
    }

    private function build(): void
    {
        $this->repository = $this->double('repository', FormRepositoryInterface::class);
        $this->tokenService = $this->double('tokenService', TokenService::class);
        $this->payload = $this->double('payload', TokenPayload::class);
        $this->json = $this->double('json', Json::class);
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnMap(
            [['form_id', null, '12'], ['product_id', null, '9'], ['edit', null, $this->edit]]
        );
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturn($this->impersonating);
        $today = $this->createMock(Today::class);
        $today->method('forStore')->with(3)->willReturn('2026-11-07');
        $translator = $this->translator = $this->double('translator', Translator::class);
        $translator->method('localize')->willReturnCallback(
            fn (FormInterface $form, int $storeId): FormInterface => $storeId === 3 ? ($this->localized ?? $form) : $form
        );
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(1800000000);
        $config = $this->createStub(Config::class);
        $config->method('getMinSubmitSeconds')->willReturnCallback(
            static fn (?int $storeId = null): int => $storeId === 3 ? 4 : 99
        );
        $this->controller = new Token(
            $jsonFactory,
            $request,
            $this->repository,
            $storeManager,
            $this->tokenService,
            $this->payload,
            $impersonation,
            $today,
            $translator,
            new ScheduleGate($clock),
            $config
        );
    }

    public function testItReturnsTheTokenWithNoStoreCacheHeaders(): void
    {
        $this->asserted = ['repository', 'tokenService', 'payload', 'json'];
        $this->build();
        $form = $this->createStub(FormInterface::class);
        $this->repository->method('getActiveById')->with(12, 3)->willReturn($form);
        $this->tokenService->method('issue')->with(12)->willReturn('123.abc');
        $this->payload->method('build')->with($form, 3, 9, '')->willReturn(['customer' => false]);
        $this->json->expects($this->once())->method('setData')
            ->with(['token' => '123.abc', 'notBefore' => 4, 'impersonating' => false, 'today' => '2026-11-07', 'customer' => false])->willReturnSelf();
        $headers = [];
        $this->json->method('setHeader')->willReturnCallback(
            function (string $name, string $value, bool $overwrite) use (&$headers) {
                $this->assertTrue($overwrite);
                $headers[$name] = $value;
                return $this->json;
            }
        );
        $this->json->expects($this->never())->method('setHttpResponseCode');

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0, private', $headers['Cache-Control']);
        $this->assertSame('no-cache', $headers['Pragma']);
        $this->assertSame('noindex', $headers['X-Robots-Tag']);
    }

    public function testItDoesNotLocaliseAnOpenFormInTheTokenOrDraftEndpoints(): void
    {
        $this->asserted = ['translator'];
        $this->build();
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->tokenService->method('issue')->willReturn('123.abc');
        $this->payload->method('build')->willReturn([]);
        $this->json->method('setData')->willReturnSelf();
        $this->json->method('setHeader')->willReturnSelf();
        $this->translator->expects($this->never())->method('localize');

        $this->controller->execute();
    }

    public function testItReturnsTheSecondsUntilTheTokenIsAcceptedInTheTokenResponse(): void
    {
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->tokenService->method('issue')->willReturn('123.abc');
        $this->payload->method('build')->willReturn([]);
        $captured = null;
        $this->json->method('setData')->willReturnCallback(function (array $data) use (&$captured) {
            $captured = $data;
            return $this->json;
        });
        $this->json->method('setHeader')->willReturnSelf();

        $this->controller->execute();

        $this->assertSame(4, $captured['notBefore']);
    }

    public function testItOmitsTheWaitWhenNoTokenIsIssued(): void
    {
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->tokenService->method('issue')->willThrowException(new \RuntimeException('no key'));
        $captured = null;
        $this->json->method('setData')->willReturnCallback(function (array $data) use (&$captured) {
            $captured = $data;
            return $this->json;
        });
        $this->json->method('setHttpResponseCode')->willReturnSelf();
        $this->json->method('setHeader')->willReturnSelf();

        $this->controller->execute();

        $this->assertArrayNotHasKey('notBefore', $captured);
    }

    public function testItReturns404JsonFromTheTokenEndpointForAnUnknownForm(): void
    {
        $this->asserted = ['repository', 'tokenService', 'payload', 'json'];
        $this->build();
        $this->repository->method('getActiveById')->with(12, 3)->willThrowException(new NoSuchEntityException());
        $this->tokenService->expects($this->never())->method('issue');
        $this->payload->expects($this->never())->method('build');
        $this->json->expects($this->once())->method('setData')->with(['token' => null])->willReturnSelf();
        $this->json->expects($this->once())->method('setHttpResponseCode')->with(404)->willReturnSelf();
        $this->json->method('setHeader')->willReturnSelf();

        $this->assertSame($this->json, $this->controller->execute());
    }

    public function testItAnswers503WithoutATokenWhenNoCryptKeyIsConfigured(): void
    {
        $this->asserted = ['payload', 'json'];
        $this->build();
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->tokenService->method('issue')->willThrowException(new \RuntimeException('no key'));
        $this->payload->expects($this->never())->method('build');
        $this->json->expects($this->once())->method('setData')->with(['token' => null])->willReturnSelf();
        $this->json->expects($this->once())->method('setHttpResponseCode')->with(503)->willReturnSelf();
        $this->json->method('setHeader')->willReturnSelf();

        $this->assertSame($this->json, $this->controller->execute());
    }

    public function testItPassesTheEditTokenToThePayload(): void
    {
        $this->asserted = ['payload', 'json'];
        $this->edit = 'tok.55.abc';
        $this->build();
        $form = $this->createStub(FormInterface::class);
        $this->repository->method('getActiveById')->willReturn($form);
        $this->tokenService->method('issue')->willReturn('123.abc');
        $this->payload->expects($this->once())->method('build')->with($form, 3, 9, 'tok.55.abc')
            ->willReturn(['customer' => true, 'edit' => 'tok.55.abc']);
        $this->json->expects($this->once())->method('setData')
            ->with(['token' => '123.abc', 'notBefore' => 4, 'impersonating' => false, 'today' => '2026-11-07', 'customer' => true, 'edit' => 'tok.55.abc'])->willReturnSelf();
        $this->json->method('setHeader')->willReturnSelf();

        $this->controller->execute();
    }

    public function testItReportsImpersonationInTheTokenResponseEvenWhenAccessIsRefused(): void
    {
        $this->asserted = ['json'];
        $this->impersonating = true;
        $this->build();
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->tokenService->method('issue')->willReturn('123.abc');
        $refused = ['customer' => false, 'access' => ['allowed' => false, 'reason' => 'login', 'loginUrl' => 'x']];
        $this->payload->method('build')->willReturn($refused);
        $this->json->expects($this->once())->method('setData')
            ->with(['token' => '123.abc', 'notBefore' => 4, 'impersonating' => true, 'today' => '2026-11-07'] + $refused)->willReturnSelf();
        $this->json->method('setHeader')->willReturnSelf();

        $this->controller->execute();
    }

    public function testItReturnsTodayInTheTokenResponseEvenWhenAccessIsRefused(): void
    {
        $this->repository->method('getActiveById')->willReturn($this->createStub(FormInterface::class));
        $this->tokenService->method('issue')->willReturn('123.abc');
        $refused = ['customer' => false, 'access' => ['allowed' => false, 'reason' => 'closed']];
        $this->payload->method('build')->willReturn($refused);
        $sent = [];
        $this->json->method('setData')->willReturnCallback(function (array $data) use (&$sent) {
            $sent = $data;
            return $this->json;
        });
        $this->json->method('setHeader')->willReturnSelf();

        $this->controller->execute();

        $this->assertSame('2026-11-07', $sent['today']);
        $this->assertSame($refused['access'], $sent['access']);
    }

    private function closedForm(): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn(['close_at' => '2020-01-01T00:00:00Z', 'closed_message' => 'Closed (default)']);
        $this->localized = $this->createStub(FormInterface::class);
        $this->localized->method('getSettings')
            ->willReturn(['close_at' => '2020-01-01T00:00:00Z', 'closed_message' => 'Ferme pour la saison']);
        $this->build();
        $this->repository->method('getActiveById')->willReturn($form);
        $this->json->method('setHeader')->willReturnSelf();

        return $form;
    }

    public function testItReturnsTheScheduleStateInTheTokenResponse(): void
    {
        $this->asserted = ['tokenService', 'payload', 'json'];
        $form = $this->closedForm();
        $this->tokenService->expects($this->never())->method('issue');
        $this->payload->expects($this->never())->method('build');
        $this->payload->method('access')->with($form)->willReturn(['access' => ['allowed' => true, 'reason' => null, 'loginUrl' => 'x']]);
        $this->json->expects($this->once())->method('setData')->with([
            'token' => null,
            'impersonating' => false,
            'today' => '2026-11-07',
            'schedule' => ['state' => 'closed', 'message' => 'Ferme pour la saison'],
            'access' => ['allowed' => true, 'reason' => null, 'loginUrl' => 'x'],
        ])->willReturnSelf();
        $this->json->expects($this->never())->method('setHttpResponseCode');

        $this->controller->execute();
    }

    public function testItReturnsTheStoreViewsClosedMessageInTheTokenResponse(): void
    {
        $this->closedForm();
        $this->payload->method('access')->willReturn([]);
        $sent = [];
        $this->json->method('setData')->willReturnCallback(function (array $data) use (&$sent) {
            $sent = $data;
            return $this->json;
        });

        $this->controller->execute();

        $this->assertSame('Ferme pour la saison', $sent['schedule']['message']);
    }

    public function testItReturnsNoPreviousAnswersOrEditStateInTheTokenResponseWhileClosed(): void
    {
        $this->asserted = ['payload'];
        $this->edit = 'tok.55.abc';
        $this->closedForm();
        $this->payload->expects($this->never())->method('build');
        $this->payload->method('access')->willReturn(['access' => ['allowed' => true, 'reason' => null, 'loginUrl' => 'x']]);
        $sent = [];
        $this->json->method('setData')->willReturnCallback(function (array $data) use (&$sent) {
            $sent = $data;
            return $this->json;
        });

        $this->controller->execute();

        foreach (['previous', 'edit', 'prefill', 'locked', 'product', 'customer'] as $key) {
            $this->assertArrayNotHasKey($key, $sent);
        }
        $this->assertNull($sent['token']);
    }
}
