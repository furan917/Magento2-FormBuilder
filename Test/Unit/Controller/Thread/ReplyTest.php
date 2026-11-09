<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Controller\Thread\Reply;
use Furan\FormBuilder\Model\Thread\CustomerReply;
use Furan\FormBuilder\Model\Thread\PrivateResponse;
use Furan\FormBuilder\Model\Thread\ReplyOutcome;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Furan\FormBuilder\Model\Thread\ThreadGrants;
use Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ReplyTest extends TestCase
{
    use AssertedDoubles;

    private array $params = ['id' => '42', 'body' => 'Thanks, that helps.'];
    private ?array $loaded = null;
    private bool $granted = true;
    private array $paths = [];
    private array $headers = [];
    private CustomerReply&Stub $reply;
    private ManagerInterface&Stub $manager;
    private Validator&Stub $formKey;

    private function build(): void
    {
        $this->reply = $this->double('reply', CustomerReply::class);
        $this->manager = $this->double('manager', ManagerInterface::class);
        $this->formKey = $this->double('formKey', Validator::class);
        $submission = $this->createStub(SubmissionInterface::class);
        $this->loaded = [
            'submission' => $submission,
            'form' => $this->createStub(FormInterface::class),
            'version' => 1,
            'fingerprints' => ['fp'],
            'recipient' => 'guest@example.com',
        ];
    }

    private function controller(): Reply
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $this->params[$key] ?? null);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path, array $params = []) use ($redirect) {
            $this->paths[] = [$path, $params];
            return $redirect;
        });
        $redirect->method('setHeader')->willReturnCallback(function (string $n, string $v) use ($redirect) {
            $this->headers[$n] = $v;
            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $access = $this->createStub(ThreadAccess::class);
        $access->method('load')->willReturnCallback(fn () => $this->loaded);
        $grants = $this->createStub(ThreadGrants::class);
        $grants->method('allows')->willReturnCallback(fn () => $this->granted);

        return new Reply($redirects, $request, $stores, $access, $grants, $this->reply, $this->formKey, $this->manager);
    }

    private function back(): array
    {
        return ['furanform/thread/view', ['_query' => ['id' => 42], '_scope_to_url' => true]];
    }

    public function testItIsAPostOnlyCsrfAwareAction(): void
    {
        $this->assertTrue(is_subclass_of(Reply::class, CsrfAwareActionInterface::class));
        $this->assertFalse(is_subclass_of(Reply::class, HttpGetActionInterface::class));
        $this->assertFalse(is_subclass_of(Reply::class, \Magento\Customer\Controller\AccountInterface::class));
    }

    public function testItValidatesTheFormKeyExplicitlyEvenForAjax(): void
    {
        $this->asserted = ['formKey'];
        $this->build();
        $request = $this->createStub(Http::class);
        $this->formKey->expects($this->once())->method('validate')->with($request)->willReturn(false);
        $this->assertFalse($this->controller()->validateForCsrf($request));
        $this->assertInstanceOf(InvalidRequestException::class, $this->controller()->createCsrfValidationException($request));
    }

    public function testItPostsTheBodyAsAGuestAndReportsSuccess(): void
    {
        $this->asserted = ['reply', 'manager'];
        $this->build();
        $this->reply->expects($this->once())->method('postAsGuest')
            ->with($this->loaded['submission'], $this->loaded['form'], 'Thanks, that helps.', 'guest@example.com', 1)
            ->willReturn(ReplyOutcome::sent());
        $this->manager->expects($this->once())->method('addSuccessMessage')->with('Your reply has been sent.');

        $this->controller()->execute();

        $this->assertSame([$this->back()], $this->paths);
        $this->assertSame('no-referrer', $this->headers['Referrer-Policy'] ?? null);
        $this->assertSame(PrivateResponse::HEADERS['Cache-Control'], $this->headers['Cache-Control'] ?? null);
    }

    public function testItShowsTheRefusalMessageOfTheReplyService(): void
    {
        $this->asserted = ['manager'];
        $this->build();
        $this->reply->method('postAsGuest')->willReturn(ReplyOutcome::refused(409, 'This conversation is closed.'));
        $this->manager->expects($this->once())->method('addErrorMessage')->with('This conversation is closed.');
        $this->manager->expects($this->never())->method('addSuccessMessage');

        $this->controller()->execute();

        $this->assertSame([$this->back()], $this->paths);
    }

    public function testItRefusesWithoutASessionGrantEvenIfTheThreadExists(): void
    {
        $this->asserted = ['reply'];
        $this->build();
        $this->granted = false;
        $this->params['t'] = 'A-VALID-LOOKING-TOKEN';
        $this->reply->expects($this->never())->method('postAsGuest');

        $this->controller()->execute();

        $this->assertSame([['furanform/thread/view', ['_scope_to_url' => true]]], $this->paths);
    }

    public function testItRefusesUnknownDeletedOrForeignStoreThreads(): void
    {
        $this->asserted = ['reply'];
        $this->build();
        $this->loaded = null;
        $this->reply->expects($this->never())->method('postAsGuest');

        $this->controller()->execute();

        $this->assertSame([['furanform/thread/view', ['_scope_to_url' => true]]], $this->paths);
    }
}
