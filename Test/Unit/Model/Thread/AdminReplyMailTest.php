<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Furan\FormBuilder\Model\Thread\AdminReplyMail;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Model\Thread\ReplyPolicy;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Furan\FormBuilder\Model\Thread\ThreadHeaders;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AdminReplyMailTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private TransportInterface&Stub $transport;
    private SubmissionInterface&Stub $submission;
    private FormInterface&Stub $form;
    private StateInterface&Stub $inlineState;
    private ThreadHeaders&Stub $threadHeaders;
    private ThreadAccess&Stub $threadAccess;
    private array $calls = [];
    private string $replyTo = '';
    private bool $customerOpen = true;
    private bool $guestOpen = true;
    private bool $threadFull = false;

    protected function setUp(): void
    {
        $this->transport = $this->createStub(TransportInterface::class);
        $this->inlineState = $this->createStub(StateInterface::class);
        $this->submission = $this->submission(4, null);
        $this->form = $this->createStub(FormInterface::class);
        $this->form->method('getTitle')->willReturn("Contact\r\nus");
        $this->form->method('getSettings')->willReturn([]);
    }

    private function submission(?int $storeId, ?int $customerId): SubmissionInterface&Stub
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(7);
        $submission->method('getStoreId')->willReturn($storeId);
        $submission->method('getCustomerId')->willReturn($customerId);
        return $submission;
    }

    private function send(string $body = 'Hello <b>there</b>', string $recipient = 'guest@example.com'): void
    {
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo', 'setReplyTo'] as $method) {
            $builder->method($method)->willReturnCallback(function (...$args) use ($method, $builder) {
                $this->calls[$method] = $args;
                return $builder;
            });
        }
        $builder->method('getTransport')->willReturn($this->transport);
        $factory = $this->createStub(TransportBuilderFactory::class);
        $factory->method('create')->willReturn($builder);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('getSenderIdentity')->willReturn('support');
        $config->method('getEmailTemplate')->willReturnCallback(static fn (string $setting, ?int $store): string => 'chosen_' . $setting . '_' . $store);
        $config->method('getReplyTo')->willReturnCallback(fn () => $this->replyTo);

        $this->threadAccess ??= $this->createStub(ThreadAccess::class);
        $this->threadAccess->method('replyUrl')->willReturn('https://shop.test/furanform/thread/view?t=TOKEN');
        $this->threadHeaders ??= $this->createStub(ThreadHeaders::class);
        $this->threadHeaders->method('rootId')->willReturn('ffb-7-0123456789abcdef@shop.test');

        $storeId = (new SubmissionStore($storeManager))->resolve($this->submission, $this->form);
        $policy = $this->createStub(ReplyPolicy::class);
        $policy->method('customerRepliesOpen')->willReturnCallback(fn (array $s, ?int $store) => $store === $storeId && $this->customerOpen);
        $policy->method('guestRepliesOpen')->willReturnCallback(fn (array $s, ?int $store) => $store === $storeId && $this->guestOpen);
        $policy->method('isFull')->willReturnCallback(fn (): bool => $this->threadFull);

        $scope = $this->createStub(StoreScope::class);
        $scope->method('run')->willReturnCallback(function (int $storeId, callable $work) {
            $this->calls['storeScope'][] = $storeId;
            return $work();
        });
        $translator = $this->createStub(Translator::class);
        $translator->method('localize')->willReturnCallback(function ($form, int $storeId) {
            $this->calls['localize'][] = $storeId;
            return $form;
        });

        (new AdminReplyMail(
            $factory,
            $this->inlineState,
            new SubmissionStore($storeManager),
            $config,
            new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()),
            $this->threadAccess,
            $this->threadHeaders,
            $policy,
            $scope,
            $translator
        ))->send($this->submission, $this->form, $recipient, $body);
    }

    public function testItEmailsTheReplyToTheRespondentFromTheSubmissionsStore(): void
    {
        $inlineState = $this->inlineState = $this->createMock(StateInterface::class);
        $transport = $this->transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('sendMessage');
        $inlineState->expects($this->once())->method('suspend');
        $inlineState->expects($this->once())->method('resume');

        $this->send();

        $this->assertSame('guest@example.com', $this->calls['addTo'][0]);
        $this->assertSame('chosen_reply_4', $this->calls['setTemplateIdentifier'][0]);
        $this->assertSame(['area' => 'frontend', 'store' => 4], $this->calls['setTemplateOptions'][0]);
        $this->assertSame(['support', 4], $this->calls['setFromByScope']);
        $this->assertSame('Re: Contact us', $this->calls['setTemplateVars'][0]['subject']);
    }

    public function testItSendsFromTheDefaultStoreViewWhenTheSubmissionHasNoStore(): void
    {
        $this->submission = $this->submission(null, 12);

        $this->send('Hi', 'account@example.com');

        $this->assertSame('account@example.com', $this->calls['addTo'][0]);
        $this->assertSame(1, $this->calls['setTemplateOptions'][0]['store']);
    }

    public function testItSendsFromTheFormsFirstStoreViewWhenTheSubmissionsStoreWasDeleted(): void
    {
        $this->submission = $this->submission(null, null);
        $this->form = $this->createStub(FormInterface::class);
        $this->form->method('getTitle')->willReturn('Contact');
        $this->form->method('getSettings')->willReturn([]);
        $this->form->method('getStoreIds')->willReturn([5, 3]);

        $this->send();

        $this->assertSame(3, $this->calls['setTemplateOptions'][0]['store']);
        $this->assertSame([3], $this->calls['storeScope']);
    }

    public function testItResumesInlineTranslationWhenTheMailFails(): void
    {
        $inlineState = $this->inlineState = $this->createMock(StateInterface::class);
        $this->transport->method('sendMessage')->willThrowException(new \Magento\Framework\Exception\MailException(__('down')));
        $inlineState->expects($this->once())->method('resume');

        $this->expectException(\Magento\Framework\Exception\MailException::class);
        $this->send();
    }

    public function testItEscapesTheReplyBodyInTheEmail(): void
    {
        $this->send();

        $this->assertSame('<p>Hello &lt;b&gt;there&lt;/b&gt;</p>', $this->calls['setTemplateVars'][0]['body_html']);
    }

    public function testItRendersTheMarkdownReplyAndBraceEncodesEveryTemplateValue(): void
    {
        $this->form = $this->createStub(FormInterface::class);
        $this->form->method('getTitle')->willReturn('Q {{template config_path="x"}}');
        $this->form->method('getSettings')->willReturn([]);

        $this->send("**Hi** {{var order.increment_id}}\n\n- [Track](https://shop.test/t)");

        $vars = $this->calls['setTemplateVars'][0];
        $this->assertStringContainsString('<strong>Hi</strong>', $vars['body_html']);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $vars['body_html']);
        foreach ($vars as $name => $value) {
            $this->assertStringNotContainsString('{', (string)$value, $name);
            $this->assertStringNotContainsString('}', (string)$value, $name);
        }
        $this->assertSame('Re: Q &#123;&#123;template config_path="x"&#125;&#125;', $vars['subject']);
        $this->assertSame('Re: Q {{template config_path="x"}}', html_entity_decode($vars['subject'], ENT_QUOTES));
    }

    public function testItLinksTheEmailToTheReplyPageIssuedForThisRecipient(): void
    {
        $this->asserted = ['threadAccess'];
        $this->threadAccess = $this->double('threadAccess', ThreadAccess::class);
        $this->threadAccess->expects($this->once())->method('replyUrl')
            ->with($this->submission, 'guest@example.com')
            ->willReturn('https://shop.test/furanform/thread/view?t=TOKEN');

        $this->send();

        $this->assertSame('https://shop.test/furanform/thread/view?t=TOKEN', $this->calls['setTemplateVars'][0]['reply_url']);
    }

    public function testItSetsTheConfiguredReplyToAndOmitsTheNotReadNote(): void
    {
        $this->replyTo = 'help@shop.test';
        $this->send();

        $this->assertSame('help@shop.test', $this->calls['setReplyTo'][0]);
        $this->assertSame('', $this->calls['setTemplateVars'][0]['reply_note']);
    }

    public function testItAddsNoReplyToButSaysRepliesAreNotReadWhenNoneIsConfigured(): void
    {
        $this->send();

        $this->assertArrayNotHasKey('setReplyTo', $this->calls);
        $this->assertSame('1', $this->calls['setTemplateVars'][0]['reply_note']);
    }

    public function testItThreadsTheEmailUnderTheSubmissionRootId(): void
    {
        $this->asserted = ['threadHeaders'];
        $this->threadHeaders = $this->double('threadHeaders', ThreadHeaders::class);
        $this->threadHeaders->expects($this->once())->method('rootId')->with(7, 4)->willReturn('ffb-7-x@shop.test');
        $this->threadHeaders->expects($this->once())->method('apply')->with($this->transport, 'ffb-7-x@shop.test');

        $this->send();
    }

    public function testItLeavesOutTheGuestReplyLinkWhenGuestRepliesAreOff(): void
    {
        $this->asserted = ['threadAccess'];
        $this->guestOpen = false;
        $this->threadAccess = $this->double('threadAccess', ThreadAccess::class);
        $this->threadAccess->expects($this->never())->method('replyUrl');

        $this->send();

        $this->assertSame('', $this->calls['setTemplateVars'][0]['reply_url']);
        $this->assertSame('', $this->calls['setTemplateVars'][0]['reply_by_email']);
        $this->assertSame('1', $this->calls['setTemplateVars'][0]['reply_note']);
    }

    public function testItInvitesAnEmailReplyInsteadWhenRepliesAreOffAndAReplyToIsSet(): void
    {
        $this->guestOpen = false;
        $this->replyTo = 'help@shop.test';

        $this->send();

        $this->assertSame('', $this->calls['setTemplateVars'][0]['reply_url']);
        $this->assertSame('1', $this->calls['setTemplateVars'][0]['reply_by_email']);
        $this->assertSame('', $this->calls['setTemplateVars'][0]['reply_note']);
    }

    public function testItUsesTheCustomerSettingForLinkedSubmissions(): void
    {
        $this->submission = $this->submission(4, 12);
        $this->guestOpen = false;

        $this->send();
        $this->assertNotSame('', $this->calls['setTemplateVars'][0]['reply_url']);

        $this->calls = [];
        $this->customerOpen = false;
        $this->send();
        $this->assertSame('', $this->calls['setTemplateVars'][0]['reply_url']);
    }

    public function testItInvitesNoWebsiteReplyToAFullThread(): void
    {
        $this->threadFull = true;

        $this->send();

        $this->assertSame('', $this->calls['setTemplateVars'][0]['reply_url']);
    }

    public function testItBuildsTheEmailAsTheSubmissionsStoreWithTheFormTitleInItsLanguage(): void
    {
        $this->send();

        $this->assertSame([4], $this->calls['storeScope']);
        $this->assertSame([4], $this->calls['localize']);
    }
}
