<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Email\NotificationAction;
use Furan\FormBuilder\Model\Mail\MailQueue;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Furan\FormBuilder\Model\Thread\CustomerReplyNotifier;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
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
use Psr\Log\LoggerInterface;

class CustomerReplyNotifierTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private TransportBuilderFactory&Stub $factory;
    private NotificationAction&Stub $recipients;
    private LoggerInterface&Stub $logger;
    private TransportInterface&Stub $transport;
    private ThreadHeaders&Stub $threadHeaders;
    private ?MailQueue $mailQueue = null;
    /** @var array<int, array{0: string, 1: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?LoggerInterface $logger = null, ?NotificationAction $recipients = null, ?TransportInterface $transport = null): void
    {
        $this->calls = [];
        $this->factory = $this->createStub(TransportBuilderFactory::class);
        $this->recipients = $recipients ?? $this->createStub(NotificationAction::class);
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $this->transport = $transport ?? $this->createStub(TransportInterface::class);
        $this->threadHeaders = $this->double('threadHeaders', ThreadHeaders::class);
        $this->threadHeaders->method('rootId')->willReturn('ffb-8-0123456789abcdef@shop.test');
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo',
                     'setReplyTo'] as $method) {
            $builder->method($method)->willReturnCallback(
                function (...$args) use ($method, $builder) {
                    $this->calls[] = [$method, $args];
                    return $builder;
                }
            );
        }
        $builder->method('getTransport')->willReturn($this->transport);
        $this->factory->method('create')->willReturn($builder);
    }

    private function notifier(): CustomerReplyNotifier
    {
        $config = $this->createStub(Config::class);
        $config->method('getSenderIdentity')->willReturn('support');
        $config->method('getEmailTemplate')->willReturnCallback(static fn (string $setting, ?int $store): string => 'chosen_' . $setting . '_' . $store);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(4);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        return new CustomerReplyNotifier(
            $this->factory,
            $this->createStub(StateInterface::class),
            $config,
            $this->recipients,
            new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()),
            $this->threadHeaders,
            $this->logger,
            $this->storeScope(),
            $this->translator(),
            \Furan\FormBuilder\Test\Unit\Helper\EmailConditionsFactory::create(),
            new SubmissionStore($storeManager),
            $this->mailQueue ?? $this->createStub(MailQueue::class),
            $this->backendUrl()
        );
    }

    private function backendUrl(): \Magento\Backend\Model\UrlInterface
    {
        $url = $this->createStub(\Magento\Backend\Model\UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => 'https://shop.test/admin/' . $route . '/id/' . $params['id']
                . (!empty($params['_nosecret']) ? '' : '/key/abc')
        );

        return $url;
    }

    private function form(array $settings): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn("Contact\r\nBcc: x");
        $form->method('getSettings')->willReturn($settings);

        return $form;
    }

    private function submission(): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(8);
        $submission->method('getReference')->willReturn('0194f3a0-1111-7222-8333-444455556666');
        $submission->method('getStoreId')->willReturn(null);
        $submission->method('getPayload')->willReturn([
            ['name' => 'topic', 'label' => 'Topic', 'type' => 'select', 'value' => 'sales'],
        ]);

        return $submission;
    }

    private function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $method));
    }

    public function testItNotifiesTheFormsNotificationRecipientsOfACustomerReply(): void
    {
        $recipients = $this->createMock(NotificationAction::class);
        $transport = $this->createMock(TransportInterface::class);
        $this->fixture(recipients: $recipients, transport: $transport);
        $settings = ['notify_enabled' => true, 'notify_recipients' => ['a@x.test']];
        $recipients->expects($this->once())->method('resolveRecipients')
            ->with($settings, ['topic' => 'sales'], $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/'), [])
            ->willReturn(['sales@x.test', 'b@x.test']);
        $transport->expects($this->once())->method('sendMessage');

        $this->notifier()->notify($this->form($settings), $this->submission(), 31, 'cust@x.test', "<b>Hi</b>\nthere");

        $this->assertSame('chosen_customer_reply_4', $this->callsTo('setTemplateIdentifier')[0][1][0]);
        $this->assertSame(
            ['sales@x.test', 'b@x.test'],
            array_map(static fn (array $c): string => $c[1][0], $this->callsTo('addTo'))
        );
        $this->assertSame('support', $this->callsTo('setFromByScope')[0][1][0]);
        $this->assertSame('cust@x.test', $this->callsTo('setReplyTo')[0][1][0]);
        $vars = $this->callsTo('setTemplateVars')[0][1][0];
        $this->assertStringContainsString('&lt;b&gt;Hi&lt;/b&gt;<br />', $vars['body_html']);
        $this->assertStringNotContainsString('<b>', $vars['body_html']);
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $vars['subject']);
        $this->assertCount(1, $this->scopes, 'built inside the submission store scope');
    }

    public function testItGivesTheReplyNotificationTheSubmissionReferenceAndAnAdminLink(): void
    {
        $recipients = $this->createStub(NotificationAction::class);
        $recipients->method('resolveRecipients')->willReturn(['sales@x.test']);
        $this->fixture(recipients: $recipients);

        $this->notifier()->send(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['sales@x.test']]),
            $this->submission(),
            'cust@x.test',
            'Hi'
        );

        $vars = $this->callsTo('setTemplateVars')[0][1][0];
        $this->assertSame(8, $vars['submission_id']);
        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $vars['reference']);
        $this->assertSame('https://shop.test/admin/furanform/submission/open/id/8', $vars['admin_url']);
    }

    public function testItQueuesTheNotificationByMessageIdWhenMailIsAsync(): void
    {
        $queue = $this->mailQueue = $this->createMock(MailQueue::class);
        $queue->expects($this->once())->method('publishReply')->with(31)->willReturn(true);
        $transport = $this->createMock(TransportInterface::class);
        $this->fixture(transport: $transport);
        $this->recipients->method('resolveRecipients')->willReturn(['a@x.test']);
        $transport->expects($this->never())->method('sendMessage');

        $this->notifier()->notify($this->form(['notify_enabled' => true]), $this->submission(), 31, 'c@x.test', 'Hi');
    }

    public function testItSendsInlineWhenTheReplyIsNotQueued(): void
    {
        $queue = $this->mailQueue = $this->createMock(MailQueue::class);
        $queue->expects($this->once())->method('publishReply')->with(31)->willReturn(false);
        $transport = $this->createMock(TransportInterface::class);
        $this->fixture(transport: $transport);
        $this->recipients->method('resolveRecipients')->willReturn(['a@x.test']);
        $transport->expects($this->once())->method('sendMessage');

        $this->notifier()->notify($this->form(['notify_enabled' => true]), $this->submission(), 31, 'c@x.test', 'Hi');
    }

    public function testItQueuesNothingWhenNotificationsAreOff(): void
    {
        $queue = $this->mailQueue = $this->createMock(MailQueue::class);
        $queue->expects($this->never())->method('publishReply');

        $this->notifier()->notify($this->form(['notify_enabled' => false]), $this->submission(), 31, 'c@x.test', 'Hi');
    }

    public function testItSendsAsTheFormsStoreViewWhenTheSubmissionsStoreViewWasDeleted(): void
    {
        $this->recipients->method('resolveRecipients')->willReturn(['a@x.test']);
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact');
        $form->method('getSettings')->willReturn(['notify_enabled' => true]);
        $form->method('getStoreIds')->willReturn([6]);

        $this->notifier()->send($form, $this->submission(), 'c@x.test', 'Hi');

        $this->assertSame('chosen_customer_reply_6', $this->callsTo('setTemplateIdentifier')[0][1][0]);
        $this->assertSame([6], $this->scopes);
    }

    public function testItSendsNothingWhenNotificationsAreOffOrNobodyIsResolved(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $this->fixture(transport: $transport);
        $transport->expects($this->never())->method('sendMessage');
        $this->recipients->method('resolveRecipients')->willReturn([]);

        $this->notifier()->notify($this->form(['notify_enabled' => false]), $this->submission(), 31, 'c@x.test', 'Hi');
        $this->notifier()->notify($this->form(['notify_enabled' => true]), $this->submission(), 31, 'c@x.test', 'Hi');
    }

    public function testItOmitsAnUnsafeReplyToAndLogsMailFailures(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->recipients->method('resolveRecipients')->willReturn(['a@x.test']);
        $this->transport->method('sendMessage')->willThrowException(new \RuntimeException('smtp down'));
        $logger->expects($this->once())->method('error');

        $this->notifier()->notify($this->form(['notify_enabled' => true]), $this->submission(), 31, "a@x.test\nBcc: z", 'Hi');

        $this->assertSame([], $this->callsTo('setReplyTo'));
    }

    public function testItBraceEncodesTheCustomerTextAndTitleForTheTemplate(): void
    {
        $this->recipients->method('resolveRecipients')->willReturn(['a@x.test']);
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('T {{var x}}');
        $form->method('getSettings')->willReturn(['notify_enabled' => true]);

        $this->notifier()->notify($form, $this->submission(), 31, 'c@x.test', '{{template config_path="x"}} **not bold**');

        $vars = $this->callsTo('setTemplateVars')[0][1][0];
        foreach ($vars as $name => $value) {
            $this->assertStringNotContainsString('{', (string)$value, $name);
        }
        $this->assertStringContainsString('**not bold**', $vars['body_html']);
    }

    public function testItThreadsTheStoreNotificationUnderTheSubmissionRootId(): void
    {
        $this->asserted = ['threadHeaders'];
        $this->recipients->method('resolveRecipients')->willReturn(['a@x.test']);
        $this->threadHeaders = $this->double('threadHeaders', ThreadHeaders::class);
        $this->threadHeaders->expects($this->once())->method('rootId')->with(8, 4)->willReturn('ffb-8-x@shop.test');
        $this->threadHeaders->expects($this->once())->method('apply')->with($this->transport, 'ffb-8-x@shop.test');

        $this->notifier()->notify($this->form(['notify_enabled' => true]), $this->submission(), 31, 'c@x.test', 'Hi');
    }

    public function testItNeverLogsTheExceptionMessageWhichMayContainAddresses(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->recipients->method('resolveRecipients')->willReturn(['a@x.test']);
        $this->transport->method('sendMessage')->willThrowException(new \RuntimeException('rejected secret@x.test'));
        $logger->expects($this->once())->method('error')->with(
            $this->callback(static fn (string $line): bool => !str_contains($line, '@'))
        );

        $this->notifier()->notify($this->form(['notify_enabled' => true]), $this->submission(), 31, 'c@x.test', 'Hi');
    }

    /** @var int[] */
    private array $scopes = [];

    private function storeScope(): \Furan\FormBuilder\Model\Email\StoreScope
    {
        $scope = $this->createStub(\Furan\FormBuilder\Model\Email\StoreScope::class);
        $scope->method('run')->willReturnCallback(function (int $storeId, callable $work) {
            $this->scopes[] = $storeId;
            return $work();
        });
        return $scope;
    }

    private function translator(): \Furan\FormBuilder\Model\I18n\Translator
    {
        $translator = $this->createStub(\Furan\FormBuilder\Model\I18n\Translator::class);
        $translator->method('localize')->willReturnArgument(0);
        return $translator;
    }
}
