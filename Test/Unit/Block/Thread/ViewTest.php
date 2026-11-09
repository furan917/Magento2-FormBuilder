<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Block\Thread\View;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Thread\CurrentThread;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\ReplyPolicy;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    private int $status = SubmissionInterface::STATUS_ANSWERED;
    private bool $impersonating = false;
    private bool $guestOpen = true;
    private bool $full = false;
    private int $fullChecks = 0;
    private array $params = [];
    /** @var array<int, array{0: string, 1: array}> */
    private array $urlParams = [];

    private function block(?array $context, ?Messages $messages = null): View
    {
        $store = $this->createStub(Store::class);
        $store->method('getFrontendName')->willReturn('Acme Shop');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $blockContext = $this->createStub(Context::class);
        $blockContext->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $blockContext->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $blockContext->method('getStoreManager')->willReturn($stores);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $this->params[$key] ?? null);
        $urlBuilder = $this->createStub(\Magento\Framework\UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(function (string $route, array $params): string {
            $this->urlParams[] = [$route, $params];
            return 'https://shop.test/' . $route;
        });
        $blockContext->method('getUrlBuilder')->willReturn($urlBuilder);
        $blockContext->method('getRequest')->willReturn($request);
        $policy = $this->createStub(ReplyPolicy::class);
        $policy->method('guestRepliesOpen')->willReturnCallback(fn (array $settings) => $this->guestOpen);
        $policy->method('isFull')->willReturnCallback(function (int $id) {
            $this->fullChecks++;
            return $id === 42 && $this->full;
        });
        $current = new CurrentThread();
        $current->set($context);
        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturnCallback(fn () => $this->impersonating);
        return new View(
            $blockContext,
            $current,
            $messages ?? $this->emptyThread(),
            new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()),
            $impersonation,
            $policy,
            new SubmissionStatus()
        );
    }

    private function emptyThread(): Messages
    {
        $messages = $this->createStub(Messages::class);
        $messages->method('pageForCustomer')->willReturn(['messages' => [], 'earlier' => false]);
        return $messages;
    }

    private function context(): array
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(42);
        $submission->method('getStatus')->willReturnCallback(fn () => $this->status);
        $submission->method('getCreatedAt')->willReturn('2026-11-01 09:00:00');
        $submission->method('getPayload')->willReturn([['name' => 'secret', 'value' => 'ANSWER-VALUE']]);
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact us');
        return ['submission' => $submission, 'form' => $form, 'version' => 0, 'fingerprints' => ['x'], 'recipient' => 'g@x.test'];
    }

    public function testItKeepsTheStoreMarkerOnThreadPageUrls(): void
    {
        $block = $this->block($this->context());
        $block->getReplyUrl();
        $block->getEarlierUrl(5);
        $block->getLatestUrl();

        $this->assertCount(3, $this->urlParams);
        foreach ($this->urlParams as [$route, $params]) {
            $this->assertTrue($params['_scope_to_url'], $route);
            $this->assertArrayNotHasKey('_scope', $params, $route);
        }
    }

    public function testItHasNoViewWithoutAVerifiedThread(): void
    {
        $this->assertNull($this->block(null)->getView());
    }

    public function testItShowsTitleDateStatusAndTheCustomerFacingThreadUnderTheStoreName(): void
    {
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('pageForCustomer')->with(42, 'Acme Shop', null)->willReturn(['messages' => [
            ['message_id' => 1, 'author' => 'admin', 'author_name' => 'Acme Shop', 'body' => 'Hi', 'created_at' => '2026-11-02'],
        ], 'earlier' => false]);
        $messages->expects($this->never())->method('page');

        $view = $this->block($this->context(), $messages)->getView();

        $this->assertSame('Contact us', $view['title']);
        $this->assertSame('2026-11-01 09:00:00', $view['created_at']);
        $this->assertSame('Answered', $view['status_label']);
        $this->assertTrue($view['can_reply']);
        $this->assertSame('open', $view['reply_state']);
        $this->assertFalse($view['earlier']);
        $this->assertSame('Acme Shop', $view['thread'][0]['author_name']);
        $this->assertStringNotContainsString('ANSWER-VALUE', json_encode($view));
    }

    public function testItIsReadOnlyWhenClosedOrImpersonating(): void
    {
        $this->status = SubmissionInterface::STATUS_CLOSED;
        $view = $this->block($this->context())->getView();
        $this->assertTrue($view['closed']);
        $this->assertFalse($view['can_reply']);

        $this->status = SubmissionInterface::STATUS_ANSWERED;
        $this->impersonating = true;
        $this->assertFalse($this->block($this->context())->getView()['can_reply']);
    }

    public function testItRendersStoreRepliesAsMarkdownAndCustomerTextPlain(): void
    {
        $block = $this->block($this->context());
        $this->assertSame('<p><strong>b</strong></p>', $block->renderMessage(['author' => 'admin', 'body' => '**b**']));
        $this->assertSame('**b** &lt;i&gt;', $block->renderMessage(['author' => 'customer', 'body' => '**b** <i>']));
    }

    public function testItShowsTheThreadReadOnlyWhenGuestRepliesAreOff(): void
    {
        $this->guestOpen = false;

        $view = $this->block($this->context())->getView();

        $this->assertFalse($view['can_reply']);
        $this->assertSame('off', $view['reply_state']);
        $this->assertSame(0, $this->fullChecks);
    }

    public function testItReplacesTheReplyBoxWhenTheThreadIsFull(): void
    {
        $this->full = true;

        $view = $this->block($this->context())->getView();

        $this->assertFalse($view['can_reply']);
        $this->assertSame('full', $view['reply_state']);
    }

    public function testItPagesBackWithADigitsOnlyBeforeParameter(): void
    {
        $this->params = ['before' => '77'];
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('pageForCustomer')->with(42, 'Acme Shop', 77)
            ->willReturn(['messages' => [], 'earlier' => true]);

        $view = $this->block($this->context(), $messages)->getView();

        $this->assertTrue($view['earlier']);
        $this->assertSame(77, $view['before']);

        $this->params = ['before' => '7x'];
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('pageForCustomer')->with(42, 'Acme Shop', null)
            ->willReturn(['messages' => [], 'earlier' => false]);
        $this->block($this->context(), $messages)->getView();
    }
}
