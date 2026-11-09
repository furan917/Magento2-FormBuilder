<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\WebhookDelivery;

use Furan\FormBuilder\Controller\Adminhtml\WebhookDelivery\Redeliver;
use Furan\FormBuilder\Controller\Adminhtml\WebhookDelivery\Resend;
use Furan\FormBuilder\Model\Webhook\DeliveryReplay;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;

class ReplayControllersTest extends ControllerTestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private DeliveryReplay $replay;

    /** @param string[] $granted */
    private function build(string $class, array $granted): object
    {
        $request = $this->createStub(Http::class);
        $request->method('getPost')->willReturnCallback(static fn (?string $key = null) => $key === 'delivery_id' ? '40' : null);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => in_array($r, $granted, true));
        $user = new class {
            public function getId(): int
            {
                return 9;
            }
        };
        $session = $this->createStub(Auth::class);
        $session->method('getUser')->willReturn($user);
        $context = $this->createStub(\Magento\Backend\App\Action\Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuthorization')->willReturn($auth);
        $context->method('getAuth')->willReturn($session);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $factory = $this->createStub(\Magento\Backend\Model\View\Result\RedirectFactory::class);
        $factory->method('create')->willReturn($this->redirect);
        $context->method('getResultRedirectFactory')->willReturn($factory);
        $this->replay = $this->double('replay', DeliveryReplay::class);

        return new $class($context, $this->replay);
    }

    private const BOTH = ['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::webhooks_replay'];

    public function testItRedeliversWithTheSameIdAndResendsWithANewOne(): void
    {
        $this->asserted = ['replay'];
        $redirect = $this->expectingRedirect();
        $redeliver = $this->build(Redeliver::class, self::BOTH);
        $this->replay->expects($this->once())->method('replay')->with(40, 9, false, $this->anything())->willReturn(41);
        $redirect->expects($this->once())->method('setPath')->with('*/*/view', ['id' => 41])->willReturnSelf();
        $redeliver->execute();
    }

    public function testItResendsWithANewId(): void
    {
        $this->asserted = ['replay'];
        $redirect = $this->expectingRedirect();
        $resend = $this->build(Resend::class, self::BOTH);
        $this->replay->expects($this->once())->method('replay')->with(40, 9, true, $this->anything())->willReturn(42);
        $redirect->expects($this->once())->method('setPath')->with('*/*/view', ['id' => 42])->willReturnSelf();
        $resend->execute();
    }

    public function testItReportsARefusedReplayAndReturnsToTheDelivery(): void
    {
        $messages = $this->expectingMessages();
        $redirect = $this->expectingRedirect();
        $resend = $this->build(Resend::class, self::BOTH);
        $this->replay->method('replay')->willThrowException(new LocalizedException(__('no')));
        $messages->expects($this->once())->method('addErrorMessage');
        $redirect->expects($this->once())->method('setPath')->with('*/*/view', ['id' => 40])->willReturnSelf();
        $resend->execute();
    }

    /** @return array<string, array{string, string[], bool}> */
    public static function access(): array
    {
        return [
            'redeliver both' => [Redeliver::class, self::BOTH, true],
            'redeliver replay only' => [Redeliver::class, ['Furan_FormBuilder::webhooks_replay'], false],
            'redeliver view only' => [Redeliver::class, ['Furan_FormBuilder::webhooks'], false],
            'resend both' => [Resend::class, self::BOTH, true],
            'resend replay only' => [Resend::class, ['Furan_FormBuilder::webhooks_replay'], false],
            'resend view only' => [Resend::class, ['Furan_FormBuilder::webhooks'], false],
        ];
    }

    /** @param string[] $granted */
    #[DataProvider('access')]
    public function testItRefusesReplayWithoutTheReplayPermission(string $class, array $granted, bool $expected): void
    {
        $controller = $this->build($class, $granted);

        $this->assertSame($expected, (new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
    }
}
