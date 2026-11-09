<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Controller\Adminhtml\Webhook\RotateSecret;
use Furan\FormBuilder\Controller\Adminhtml\Webhook\TestSend;
use Furan\FormBuilder\Model\Webhook\EndpointActions;
use Furan\FormBuilder\Model\Webhook\EndpointSecret;
use Furan\FormBuilder\Model\Webhook\SecretStash;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;

class ActionControllersTest extends ControllerTestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private EndpointActions $actions;
    private SecretStash $stash;

    /** @param string[] $granted */
    private function build(string $class, array $granted, array $post = ['id' => '5', 'window_hours' => '24']): object
    {
        $request = $this->createStub(Http::class);
        $request->method('getPost')->willReturnCallback(
            static fn (?string $key = null) => $key === null ? $post : ($post[$key] ?? null)
        );
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => in_array($resource, $granted, true)
        );
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
        $this->actions = $this->double('actions', EndpointActions::class);
        $this->stash = $this->double('stash', SecretStash::class);

        return $class === RotateSecret::class
            ? new RotateSecret($context, $this->actions, $this->stash)
            : new TestSend($context, $this->actions);
    }

    private const ALL = [
        'Furan_FormBuilder::webhooks',
        'Furan_FormBuilder::webhooks_manage',
        'Furan_FormBuilder::submissions_export',
    ];

    public function testItRotatesTheSecretAndShowsTheNewOneOnce(): void
    {
        $this->asserted = ['actions', 'stash'];
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 5])->willReturnSelf();
        $controller = $this->build(RotateSecret::class, self::ALL);
        $this->actions->expects($this->once())->method('rotate')->with(5, 24, 9)
            ->willReturn(new EndpointSecret(5, 'whsec_new'));
        $this->stash->expects($this->once())->method('put')->with(5, 'whsec_new');

        $controller->execute();
    }

    public function testItDoesNotStashASecretWhenRotationFails(): void
    {
        $this->asserted = ['stash'];
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $controller = $this->build(RotateSecret::class, self::ALL);
        $this->actions->method('rotate')->willThrowException(new LocalizedException(__('no')));
        $this->stash->expects($this->never())->method('put');
        $this->redirect->method('setPath')->willReturnSelf();

        $controller->execute();
    }

    public function testItQueuesATestDeliveryFromTheController(): void
    {
        $this->asserted = ['actions'];
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $controller = $this->build(TestSend::class, self::ALL);
        $this->actions->expects($this->once())->method('sendTest')->with(5, 9)->willReturn(1);
        $this->redirect->method('setPath')->willReturnSelf();

        $controller->execute();
    }

    public function testItShowsTheReasonWhenATestSendIsRefused(): void
    {
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $controller = $this->build(TestSend::class, self::ALL);
        $this->actions->method('sendTest')->willThrowException(new LocalizedException(__('Too many')));
        $this->redirect->method('setPath')->willReturnSelf();

        $controller->execute();
    }

    public function testItRefusesBothActionsWithoutManagePermission(): void
    {
        foreach ([RotateSecret::class, TestSend::class] as $class) {
            $controller = $this->build($class, ['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::submissions_export']);
            $this->assertFalse((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller), $class);
            $this->assertSame('Furan_FormBuilder::webhooks_manage', $class::ADMIN_RESOURCE);
            $this->assertInstanceOf(\Magento\Framework\App\Action\HttpPostActionInterface::class, $controller);
        }
    }
}
