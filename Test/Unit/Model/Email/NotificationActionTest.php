<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Email;

use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Email\NotificationAction;
use Furan\FormBuilder\Model\Email\SubmissionVars;
use Furan\FormBuilder\Model\Email\ValuesTable;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\Email\MailNotSentException;
use Furan\FormBuilder\Model\Email\SubmissionMailer;
use Furan\FormBuilder\Model\Thread\ThreadHeaders;
use Furan\FormBuilder\Model\Submission\StoreDisplayContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;
use Furan\FormBuilder\Test\Unit\Helper\EmailConditionsFactory;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Test\Unit\Helper\WorkEmailType;
use Furan\FormBuilder\Model\FieldType\Pool;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationActionTest extends TestCase
{
    private TransportBuilderFactory&Stub $factory;
    private StateInterface&Stub $inlineState;
    private ?TransportInterface $transport = null;
    private ?ThreadHeaders $threadHeaders = null;

    protected function setUp(): void
    {
        $this->factory = $this->createStub(TransportBuilderFactory::class);
        $this->inlineState = $this->createStub(StateInterface::class);
    }

    private function builder(array &$calls): TransportBuilder&Stub
    {
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo',
                     'addCc', 'addBcc', 'setReplyTo'] as $method) {
            $builder->method($method)->willReturnCallback(
                function (...$args) use (&$calls, $method, $builder) {
                    $calls[] = [$method, $args];
                    return $builder;
                }
            );
        }
        $builder->method('getTransport')->willReturn($this->transport ?? $this->createStub(TransportInterface::class));

        return $builder;
    }

    private function action(?Pool $pool = null): NotificationAction
    {
        $config = $this->createStub(Config::class);
        $config->method('getSenderIdentity')->willReturn('support');
        $config->method('getEmailTemplate')->willReturnCallback(static fn (string $setting, ?int $store): string => 'chosen_' . $setting . '_' . $store);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(4);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        return new NotificationAction(
            $this->factory,
            $this->inlineState,
            new SubmissionStore($storeManager),
            $config,
            new ValuesTable(EscaperFactory::create(), new AnswerView(new ValueFormatter(FieldPools::builtIn(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::builtIn())),
            new SubmissionVars(new SubmissionStatus()),
            EmailConditionsFactory::create(),
            $pool ?? FieldPools::builtIn(),
            $this->createStub(LoggerInterface::class),
            new SubmissionMailer($this->threadHeaders ?? $this->createStub(ThreadHeaders::class)),
            new StoreDisplayContext($this->locales())
        );
    }

    private function locales(): ScopeConfigInterface&Stub
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, mixed $store): string => [6 => 'fr_FR', 4 => 'en_GB'][$store] ?? ''
        );
        return $config;
    }

    private function form(array $settings, ?array $schema = null): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Contact');
        $form->method('getSettings')->willReturn($settings);
        $form->method('getSchema')->willReturn($schema ?? ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'name'],
            ['kind' => 'section', 'items' => [['kind' => 'field', 'type' => 'email', 'name' => 'mail']]],
        ]]]]);

        return $form;
    }

    private function submission(?array $context = null, ?array $payload = null, string $createdAt = '2026-11-07 10:00:00'): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getContext')->willReturn($context);
        $submission->method('getId')->willReturn(9);
        $submission->method('getCreatedAt')->willReturn($createdAt);
        $submission->method('getReference')->willReturn('0194f3a0-1111-7222-8333-444455556666');
        $submission->method('getStoreId')->willReturn(null);
        $submission->method('getPayload')->willReturn($payload ?? [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Bob'],
        ]);

        return $submission;
    }

    private function calls(array $calls, string $method): array
    {
        $matching = array_filter($calls, static fn($c) => $c[0] === $method);

        return array_values(array_map(static fn($c) => array_slice($c[1], 0, $method === 'setFromByScope' ? 2 : 1), $matching));
    }

    public function testItSendsTheNotificationToRecipientsWithCcAndBcc(): void
    {
        $inlineState = $this->inlineState = $this->createMock(StateInterface::class);
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $inlineState->expects($this->once())->method('suspend');
        $inlineState->expects($this->once())->method('resume');

        $this->action()->execute($this->form([
            'notify_enabled' => true,
            'notify_recipients' => ['a@example.com', 'b@example.com'],
            'notify_cc' => ['c@example.com'],
            'notify_bcc' => ['d@example.com'],
            'notify_subject' => '',
        ]), $this->submission(), ['name' => 'Bob'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame([['chosen_notification_4']], $this->calls($calls, 'setTemplateIdentifier'));
        $this->assertSame([[['area' => 'frontend', 'store' => 4]]], $this->calls($calls, 'setTemplateOptions'));
        $this->assertSame([['a@example.com'], ['b@example.com']], $this->calls($calls, 'addTo'));
        $this->assertSame([['c@example.com']], $this->calls($calls, 'addCc'));
        $this->assertSame([['d@example.com']], $this->calls($calls, 'addBcc'));
        $this->assertSame([['support', 4]], $this->calls($calls, 'setFromByScope'));
        $vars = $this->calls($calls, 'setTemplateVars')[0][0];
        $this->assertSame('New submission: Contact', $vars['subject']);
        $this->assertSame('Contact', $vars['form_title']);
        $this->assertSame(9, $vars['submission_id']);
        $this->assertStringContainsString('Bob', $vars['values_html']);
        $this->assertSame([['label' => 'Name', 'value' => 'Bob']], $vars['values']);
        $this->assertSame(['id' => 9, 'created_at' => '2026-11-07 10:00:00', 'status' => 'New', 'reference' => '0194f3a0-1111-7222-8333-444455556666'], $vars['submission']);
    }

    public function testItSendsEachEmailWithTheTemplateChosenForTheSubmissionsStoreView(): void
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getStoreId')->willReturn(6);
        $submission->method('getPayload')->willReturn([]);

        $this->action()->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]),
            $submission,
            [],
            new \Furan\FormBuilder\Api\ActionContext()
        );

        $this->assertSame([['chosen_notification_6']], $this->calls($calls, 'setTemplateIdentifier'));
    }

    public function testItWritesDatesAndCountriesInTheSubmissionsStoreViewLocale(): void
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getStoreId')->willReturn(6);
        $submission->method('getPayload')->willReturn([
            ['name' => 'when', 'label' => 'When', 'type' => 'date', 'value' => '2026-10-02'],
            ['name' => 'from', 'label' => 'From', 'type' => 'country', 'value' => 'GB'],
        ]);

        $this->action()->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]),
            $submission,
            [],
            new \Furan\FormBuilder\Api\ActionContext()
        );

        $vars = $this->calls($calls, 'setTemplateVars')[0][0];
        $this->assertSame([['label' => 'When', 'value' => '2 oct. 2026'], ['label' => 'From', 'value' => 'Royaume-Uni']], $vars['values']);
        $this->assertStringContainsString('Royaume-Uni', $vars['values_html']);
    }

    public function testItSendsAsTheFormsStoreViewWhenTheSubmissionsStoreViewWasDeleted(): void
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $form = $this->createStub(FormInterface::class);
        $form->method('getStoreIds')->willReturn([8, 3]);
        $form->method('getSettings')->willReturn(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]);

        $this->action()->execute($form, $this->submission(), [], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame([['chosen_notification_3']], $this->calls($calls, 'setTemplateIdentifier'));
        $this->assertSame([['support', 3]], $this->calls($calls, 'setFromByScope'));
    }

    public function testItBraceEncodesEveryStructuredTemplateValueSoSubmittedTextCannotBecomeADirective(): void
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getCreatedAt')->willReturn('{{var a}}');
        $submission->method('getPayload')->willReturn([
            ['name' => 'x', 'label' => '{{var l}}', 'type' => 'text', 'value' => '{{config path="a/b"}}'],
        ]);

        $this->action()->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]),
            $submission,
            [],
            new \Furan\FormBuilder\Api\ActionContext()
        );

        $encoded = json_encode($this->calls($calls, 'setTemplateVars')[0][0]['values'] + $this->calls($calls, 'setTemplateVars')[0][0]['submission']);
        $this->assertStringNotContainsString('{{', $encoded);
        $this->assertStringContainsString('&#123;&#123;config', $encoded);
    }

    public function testItSetsReplyToOnlyForAValidSubmitterEmail(): void
    {
        $settings = ['notify_enabled' => true, 'notify_recipients' => ['a@example.com']];

        $calls = [];
        $this->factory = $this->createStub(TransportBuilderFactory::class);
        $this->factory->method('create')->willReturn($this->builder($calls));
        $this->action()->execute($this->form($settings), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        $this->assertSame([['bob@example.com']], $this->calls($calls, 'setReplyTo'));

        foreach (['not-an-email', "bob@example.com\r\nBcc: evil@example.com", ''] as $bad) {
            $calls = [];
            $this->factory = $this->createStub(TransportBuilderFactory::class);
            $this->factory->method('create')->willReturn($this->builder($calls));
            $this->action()->execute($this->form($settings), $this->submission(), ['mail' => $bad], new \Furan\FormBuilder\Api\ActionContext());
            $this->assertSame([], $this->calls($calls, 'setReplyTo'));
            $this->assertCount(1, $this->calls($calls, 'addTo'));
        }
    }

    public function testItUsesTheAutoresponderFieldForReplyToWhenSet(): void
    {
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'email', 'name' => 'first'],
            ['kind' => 'field', 'type' => 'email', 'name' => 'second'],
        ]]]];
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $this->action()->execute($this->form([
            'notify_enabled' => true,
            'notify_recipients' => ['a@example.com'],
            'autoresponder_email_field' => 'second',
        ], $schema), $this->submission(), ['first' => 'one@example.com', 'second' => 'two@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame([['two@example.com']], $this->calls($calls, 'setReplyTo'));
    }

    public function testItAcceptsAThirdPartyEmailAddressTypeAsTheReplyToField(): void
    {
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => WorkEmailType::CODE, 'name' => 'work'],
        ]]]];
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $pool = new Pool([WorkEmailType::CODE => new WorkEmailType()] + FieldPools::builtIn()->all());
        $this->action($pool)->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']], $schema),
            $this->submission(),
            ['work' => 'w@example.com'],
            new \Furan\FormBuilder\Api\ActionContext()
        );

        $this->assertSame([['w@example.com']], $this->calls($calls, 'setReplyTo'));
    }

    public function testItSkipsTheNotificationWhenDisabledOrWithoutRecipients(): void
    {
        $factory = $this->factory = $this->createMock(TransportBuilderFactory::class);
        $factory->expects($this->never())->method('create');
        $this->action()->execute($this->form(['notify_enabled' => false, 'notify_recipients' => ['a@example.com']]), $this->submission(), [], new \Furan\FormBuilder\Api\ActionContext());
        $this->action()->execute($this->form(['notify_enabled' => true, 'notify_recipients' => []]), $this->submission(), [], new \Furan\FormBuilder\Api\ActionContext());
    }

    public function testItUsesAFreshTransportBuilderForEveryEmail(): void
    {
        $factory = $this->factory = $this->createMock(TransportBuilderFactory::class);
        $calls = [];
        $factory->expects($this->exactly(2))->method('create')->willReturnCallback(
            fn() => $this->builder($calls)
        );
        $form = $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]);
        $this->action()->execute($form, $this->submission(), [], new \Furan\FormBuilder\Api\ActionContext());
        $this->action()->execute($form, $this->submission(), [], new \Furan\FormBuilder\Api\ActionContext());
    }

    private function sendFailing(\Throwable $failure, bool $atSend): \Throwable
    {
        $calls = [];
        $transport = $this->createStub(TransportInterface::class);
        if ($atSend) {
            $transport->method('sendMessage')->willThrowException($failure);
        }
        $this->transport = $transport;
        $builder = $this->builder($calls);
        if (!$atSend) {
            $builder = $this->createStub(TransportBuilder::class);
            $builder->method($this->anything())->willThrowException($failure);
        }
        $this->factory = $this->createStub(TransportBuilderFactory::class);
        $this->factory->method('create')->willReturn($builder);
        try {
            $this->action()->execute(
                $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]),
                $this->submission(),
                [],
                new \Furan\FormBuilder\Api\ActionContext()
            );
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('The send did not fail.');
    }

    public function testItRetriesTheNotificationOnlyWhenTheMailTransportFailedToSend(): void
    {
        $unsent = $this->sendFailing(new MailException(new Phrase('Transport error')), true);
        $this->assertInstanceOf(MailNotSentException::class, $unsent);
        $this->assertTrue($this->action()->isRetryable($unsent));
        $this->assertSame(5, $this->action()->maxAttempts());

        $built = $this->sendFailing(new MailException(new Phrase('Invalid sender data')), false);
        $this->assertFalse($this->action()->isRetryable($built));
        $this->assertFalse($this->action()->isRetryable(new \RuntimeException('template')));
    }

    public function testItThreadsTheNotificationUnderTheSubmissionRootId(): void
    {
        $calls = [];
        $this->transport = $this->createStub(TransportInterface::class);
        $headers = $this->threadHeaders = $this->createMock(ThreadHeaders::class);
        $headers->method('rootId')->with(9, 4)->willReturn('ffb-9-0123456789abcdef@shop.test');
        $headers->expects($this->once())->method('apply')->with($this->transport, 'ffb-9-0123456789abcdef@shop.test');
        $this->factory->method('create')->willReturn($this->builder($calls));

        $this->action()->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]),
            $this->submission(),
            [],
            new \Furan\FormBuilder\Api\ActionContext()
        );
    }

    public function testItShowsOptionLabelsInTheNotificationEmail(): void
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'select', 'name' => 'topic', 'options' => [['value' => 'sales', 'label' => 'Sales team']]],
        ]]]];

        $this->action()->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']], $schema),
            $this->submission(null, [['name' => 'topic', 'label' => 'Topic', 'type' => 'select', 'value' => 'sales']]),
            [],
            new \Furan\FormBuilder\Api\ActionContext()
        );

        $vars = $this->calls($calls, 'setTemplateVars')[0][0];
        $this->assertSame([['label' => 'Topic', 'value' => 'Sales team']], $vars['values']);
        $this->assertStringContainsString('Sales team', $vars['values_html']);
    }

    public function testItResumesInlineTranslationWhenSendingFails(): void
    {
        $inlineState = $this->inlineState = $this->createMock(StateInterface::class);
        $builder = $this->createStub(TransportBuilder::class);
        $builder->method($this->anything())->willThrowException(new \RuntimeException('boom'));
        $this->factory->method('create')->willReturn($builder);
        $inlineState->expects($this->once())->method('resume');

        $this->expectException(\RuntimeException::class);
        $this->action()->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]),
            $this->submission(),
            [], new \Furan\FormBuilder\Api\ActionContext());
    }

    private function payloadOf(array $values): array
    {
        $payload = [];
        foreach ($values as $name => $value) {
            $payload[] = ['name' => $name, 'label' => $name, 'type' => 'text', 'value' => $value];
        }

        return $payload;
    }

    private function recipientsSent(array $settings, array $values, string $createdAt = '2026-11-07 10:00:00', ?array $schema = null): array
    {
        $calls = [];
        $this->factory = $this->createStub(TransportBuilderFactory::class);
        $this->factory->method('create')->willReturn($this->builder($calls));
        $this->action()->execute(
            $this->form($settings + ['notify_enabled' => true], $schema),
            $this->submission(null, $this->payloadOf($values), $createdAt),
            [],
            new \Furan\FormBuilder\Api\ActionContext()
        );

        return array_map(static fn($c) => $c[0], $this->calls($calls, 'addTo'));
    }

    private function route(string $field, string $value, array $emails, string $mode = 'add'): array
    {
        return [
            'condition' => ['match' => 'all', 'rules' => [['field' => $field, 'operator' => 'equals', 'value' => $value]]],
            'emails' => $emails,
            'mode' => $mode,
        ];
    }

    public function testItRoutesANotificationWhenAConditionGroupMatches(): void
    {
        $anyOf = ['condition' => ['match' => 'any', 'rules' => [
            ['field' => 'topics', 'operator' => 'equals', 'value' => 'b'],
            ['match' => 'all', 'rules' => [
                ['field' => 'dept', 'operator' => 'equals', 'value' => 'x'],
                ['field' => 'dept', 'operator' => 'not_empty'],
            ]],
        ]], 'emails' => ['b@example.com'], 'mode' => 'add'];
        $settings = [
            'notify_recipients' => ['a@example.com'],
            'notify_routes' => [$this->route('dept', 'Sales', ['sales@example.com', 'A@example.com']), $anyOf],
        ];
        $this->assertSame(
            ['a@example.com', 'sales@example.com', 'b@example.com'],
            $this->recipientsSent($settings, ['dept' => ' sales ', 'topics' => ['a', 'B']])
        );
    }

    public function testItDoesNotRouteWhenTheGroupDoesNotMatch(): void
    {
        $settings = [
            'notify_recipients' => ['a@example.com'],
            'notify_routes' => [
                $this->route('dept', 'sales', ['s@example.com'], 'replace'),
                ['emails' => ['all@example.com'], 'mode' => 'add'],
                ['condition' => ['match' => 'all', 'rules' => []], 'emails' => ['empty@example.com'], 'mode' => 'add'],
                ['field' => 'dept', 'value' => 'support', 'emails' => ['old@example.com'], 'mode' => 'add'],
            ],
        ];
        $this->assertSame(['a@example.com'], $this->recipientsSent($settings, ['dept' => 'support']));
        $this->assertSame(['a@example.com'], $this->recipientsSent($settings, []));
    }

    public function testItReplacesTheRecipientsWhenARuleSaysInstead(): void
    {
        $settings = [
            'notify_recipients' => ['a@example.com'],
            'notify_routes' => [
                $this->route('dept', 'sales', ['x@example.com']),
                $this->route('dept', 'sales', ['r@example.com'], 'replace'),
            ],
        ];
        $this->assertSame(['x@example.com', 'r@example.com'], $this->recipientsSent($settings, ['dept' => 'sales']));
    }

    public function testItSendsRoutedNotificationsWhenTheDefaultRecipientListIsEmpty(): void
    {
        $settings = [
            'notify_recipients' => [],
            'notify_routes' => [$this->route('dept', 'sales', ['s@example.com'])],
        ];
        $this->assertSame(['s@example.com'], $this->recipientsSent($settings, ['dept' => 'sales']));
        $this->assertSame([], $this->recipientsSent($settings, ['dept' => 'other']));
        $this->assertSame(
            ['a@example.com'],
            $this->action()->resolveRecipients(['notify_recipients' => ['a@example.com']], [])
        );
    }

    public function testItDecidesARelativeDateRouteByTheDayTheSubmissionWasCreated(): void
    {
        $schema = ['pages' => [['items' => [['kind' => 'field', 'type' => 'date', 'name' => 'visit']]]]];
        $route = ['condition' => ['match' => 'all', 'rules' => [
            ['field' => 'visit', 'operator' => 'equals', 'value_date' => ['days' => 0]],
        ]], 'emails' => ['today@example.com'], 'mode' => 'add'];
        $settings = ['notify_recipients' => [], 'notify_routes' => [$route]];

        $this->assertSame(
            ['today@example.com'],
            $this->recipientsSent($settings, ['visit' => '2026-01-10'], '2026-01-10 12:00:00', $schema)
        );
        $this->assertSame(
            [],
            $this->recipientsSent($settings, ['visit' => '2026-01-10'], '2026-01-11 12:00:00', $schema)
        );
    }

    public function testItSkipsTheNotificationWhenItsSendWhenConditionIsFalse(): void
    {
        $when = ['match' => 'all', 'rules' => [['field' => 'dept', 'operator' => 'equals', 'value' => 'sales']]];
        $settings = ['notify_recipients' => ['a@example.com'], 'notify_when' => $when];

        $this->assertSame([], $this->recipientsSent($settings, ['dept' => 'support']));
        $this->assertSame(['a@example.com'], $this->recipientsSent($settings, ['dept' => 'sales']));
        $this->assertSame(['a@example.com'], $this->recipientsSent(['notify_when' => null] + $settings, ['dept' => 'support']));
    }

    private function sentVars(?array $context): array
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $this->action()->execute(
            $this->form(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]),
            $this->submission($context),
            ['name' => 'Bob'], new \Furan\FormBuilder\Api\ActionContext());

        return $this->calls($calls, 'setTemplateVars')[0][0];
    }

    public function testItShowsTheProductNameSkuPriceAndLinkInTheNotificationEmailWhenTheSubmissionHasProductContext(): void
    {
        $vars = $this->sentVars([
            'id' => 5, 'sku' => 'SKU-1', 'name' => 'Blue Shirt', 'url' => 'https://shop.test/blue.html',
            'price' => 19.5, 'currency' => 'GBP',
        ]);

        $this->assertStringContainsString('Blue Shirt', $vars['product_html']);
        $this->assertStringContainsString('SKU-1', $vars['product_html']);
        $this->assertStringContainsString('19.50 GBP', $vars['product_html']);
        $this->assertStringContainsString('href="https://shop.test/blue.html"', $vars['product_html']);
    }

    public function testItOmitsTheProductBlockFromTheNotificationEmailWithoutProductContext(): void
    {
        $this->assertSame('', $this->sentVars(null)['product_html']);
    }

    public function testItEscapesProductContextValuesInTheNotificationEmail(): void
    {
        $vars = $this->sentVars([
            'id' => 5, 'sku' => '<i>S</i>', 'name' => '<script>x</script>', 'url' => 'javascript:alert(1)',
            'price' => 1, 'currency' => '<b>',
        ]);

        $this->assertStringNotContainsString('<script>', $vars['product_html']);
        $this->assertStringNotContainsString('<i>', $vars['product_html']);
        $this->assertStringNotContainsString('<b>', $vars['product_html']);
        $this->assertStringContainsString('&lt;script&gt;', $vars['product_html']);
        $this->assertStringNotContainsString('javascript:', $vars['product_html']);
        $this->assertStringNotContainsString('<a ', $vars['product_html']);
    }
    public function testItCanBeQueuedOnlyWhenNotificationsAreEnabled(): void
    {
        $this->assertTrue($this->action()->isApplicable($this->form(['notify_enabled' => true])));
        $this->assertFalse($this->action()->isApplicable($this->form([])));
    }

    public function testItBraceEncodesTheTitleAndSubjectPassedToTheTemplate(): void
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn('Q {{var x}}');
        $form->method('getSettings')->willReturn(['notify_enabled' => true, 'notify_recipients' => ['a@example.com'], 'notify_subject' => 'S {{template config_path="x"}}']);
        $form->method('getSchema')->willReturn(['pages' => []]);

        $this->action()->execute($form, $this->submission(), [], new \Furan\FormBuilder\Api\ActionContext());

        $vars = $this->calls($calls, 'setTemplateVars')[0][0];
        $this->assertSame('Q &#123;&#123;var x&#125;&#125;', $vars['form_title']);
        $this->assertSame('S &#123;&#123;template config_path="x"&#125;&#125;', $vars['subject']);
    }

    public function testTheDefaultSubjectStaysOnOneLineWhateverTheFormTitleHolds(): void
    {
        $calls = [];
        $this->factory->method('create')->willReturn($this->builder($calls));
        $form = $this->createStub(FormInterface::class);
        $form->method('getTitle')->willReturn("Contact\r\nBcc: x@example.com");
        $form->method('getSettings')->willReturn(['notify_enabled' => true, 'notify_recipients' => ['a@example.com']]);
        $form->method('getSchema')->willReturn(['pages' => []]);

        $this->action()->execute($form, $this->submission(), [], new \Furan\FormBuilder\Api\ActionContext());

        $subject = $this->calls($calls, 'setTemplateVars')[0][0]['subject'];
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringContainsString('Contact Bcc: x@example.com', $subject);
    }
}
