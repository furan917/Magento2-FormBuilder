<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Email;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Email\AutoresponderAction;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AutoresponderActionTest extends TestCase
{
    /** @var array<int, array{string, array}> */
    private array $calls = [];
    private ?MemoryCounter $counter = null;
    private int $sent = 0;
    private int $builders = 0;
    private array $logged = [];
    private ?\Throwable $sendFailure = null;
    private ?\Throwable $buildFailure = null;
    private ?\Furan\FormBuilder\Model\Thread\ThreadHeaders $threadHeaders = null;

    private function action(): AutoresponderAction
    {
        $factory = $this->createStub(TransportBuilderFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $this->builders++;
            $builder = $this->createStub(TransportBuilder::class);
            foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo',
                         'addCc', 'addBcc', 'setReplyTo'] as $method) {
                $builder->method($method)->willReturnCallback(function (...$args) use ($method, $builder) {
                    $this->calls[] = [$method, $args];
                    return $builder;
                });
            }
            $transport = $this->createStub(TransportInterface::class);
            $transport->method('sendMessage')->willReturnCallback(function () {
                if ($this->sendFailure !== null) {
                    throw $this->sendFailure;
                }
                $this->sent++;
            });
            $builder->method('getTransport')->willReturnCallback(function () use ($transport) {
                if ($this->buildFailure !== null) {
                    throw $this->buildFailure;
                }
                return $transport;
            });

            return $builder;
        });

        $this->counter ??= new MemoryCounter();

        $config = $this->createStub(Config::class);
        $config->method('getSenderIdentity')->willReturn('support');
        $config->method('getEmailTemplate')->willReturnCallback(static fn (string $setting, ?int $store): string => 'chosen_' . $setting . '_' . $store);
        $config->method('getAutoresponderDailyLimit')->willReturnCallback(fn (): int => $this->dailyLimit);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($message) {
            $this->logged[] = (string)$message;
        });

        return new AutoresponderAction(
            $factory,
            $this->createStub(StateInterface::class),
            new SubmissionStore($storeManager),
            $config,
            $this->counter,
            $logger,
            EscaperFactory::create(),
            $this->notifier(),
            new \Furan\FormBuilder\Model\Email\SubmissionVars(new \Furan\FormBuilder\Model\Source\SubmissionStatus()),
            \Furan\FormBuilder\Test\Unit\Helper\EmailConditionsFactory::create(),
            new \Furan\FormBuilder\Model\Email\SubmissionMailer(
                $this->threadHeaders ?? $this->createStub(\Furan\FormBuilder\Model\Thread\ThreadHeaders::class)
            )
        );
    }

    private function form(array $settings = []): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(5);
        $form->method('getTitle')->willReturn('Contact');
        $form->method('getSettings')->willReturn($settings + [
            'autoresponder_enabled' => true,
            'autoresponder_email_field' => 'mail',
            'autoresponder_subject' => 'Thanks',
            'autoresponder_body' => "Hi <b>there</b>\nBye",
        ]);

        return $form;
    }

    private function submission(?array $payload = null): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(9);
        $submission->method('getFormId')->willReturn(5);
        $submission->method('getCreatedAt')->willReturn('2026-11-07 10:00:00');
        $submission->method('getReference')->willReturn('0194f3a0-1111-7222-8333-444455556666');
        $submission->method('getStoreId')->willReturn(null);
        $submission->method('getPayload')->willReturn($payload ?? [
            ['name' => 'secret', 'label' => 'Secret', 'type' => 'text', 'value' => 'TOPSECRET'],
        ]);

        return $submission;
    }

    private function first(string $method): array
    {
        foreach ($this->calls as $call) {
            if ($call[0] === $method) {
                return $call[1];
            }
        }

        return [];
    }

    public function testItSendsTheAutoresponderToTheSubmittedEmailField(): void
    {
        $this->action()->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame(1, $this->sent);
        $this->assertSame('chosen_autoresponder_2', $this->first('setTemplateIdentifier')[0]);
        $this->assertSame('bob@example.com', $this->first('addTo')[0]);
        $this->assertSame(['area' => 'frontend', 'store' => 2], $this->first('setTemplateOptions')[0]);
        $this->assertSame('support', $this->first('setFromByScope')[0]);
        $this->assertSame([], $this->first('setReplyTo'));
        $vars = $this->first('setTemplateVars')[0];
        $this->assertSame('Thanks', $vars['subject']);
        $this->assertSame('Contact', $vars['form_title']);
        $this->assertSame('Hi &lt;b&gt;there&lt;/b&gt;<br />Bye', $vars['body_html']);
    }

    public function testItRetriesTheAutoresponderOnlyWhenTheMailTransportFailedToSend(): void
    {
        $action = $this->action();
        $this->sendFailure = new \Magento\Framework\Exception\MailException(new \Magento\Framework\Phrase('Transport error'));
        try {
            $action->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
            $this->fail('The send did not fail.');
        } catch (\Furan\FormBuilder\Model\Email\MailNotSentException $e) {
            $this->assertTrue($action->isRetryable($e));
        }

        $this->assertFalse($action->isRetryable(new \Magento\Framework\Exception\MailException(new \Magento\Framework\Phrase('Invalid sender data'))));
        $this->assertFalse($action->isRetryable(new \RuntimeException('template')));
    }

    public function testAMessageThatCannotBeBuiltUsesNoQuota(): void
    {
        $action = $this->action();
        $this->buildFailure = new \Magento\Framework\Exception\MailException(new \Magento\Framework\Phrase('Invalid sender data'));
        try {
            $action->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
            $this->fail('The build did not fail.');
        } catch (\Magento\Framework\Exception\MailException $e) {
            $this->assertFalse($action->isRetryable($e));
        }
        $this->assertSame([], $this->counter->rows);

        $this->buildFailure = null;
        $action->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        $this->assertSame(1, $this->sent);
    }

    public function testItSendsARetriedAutoresponderOnceWithoutCountingItsQuotaAgain(): void
    {
        $action = $this->action();
        $this->sendFailure = new \Magento\Framework\Exception\MailException(new \Magento\Framework\Phrase('Transport error'));
        try {
            $action->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        } catch (\Furan\FormBuilder\Model\Email\MailNotSentException) {
        }
        $counted = $this->counter->rows;
        $this->sendFailure = null;

        $action->execute(
            $this->form(),
            $this->submission(),
            ['mail' => 'bob@example.com'],
            new \Furan\FormBuilder\Api\ActionContext(false, 2, null, 2)
        );

        $this->assertSame(1, $this->sent);
        $this->assertSame($counted, $this->counter->rows);
        $action->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        $this->assertSame(1, $this->sent);
    }

    private function failFirstAttempt(AutoresponderAction $action, string $mail): void
    {
        $this->sendFailure = new \Magento\Framework\Exception\MailException(new \Magento\Framework\Phrase('Recipient refused'));
        try {
            $action->execute($this->form(), $this->submission(), ['mail' => $mail], new \Furan\FormBuilder\Api\ActionContext());
            $this->fail('The send did not fail.');
        } catch (\Furan\FormBuilder\Model\Email\MailNotSentException) {
        }
        $this->sendFailure = null;
    }

    public function testItChargesTheNewAddressWhenTheAnswerWasEditedBeforeTheRetry(): void
    {
        $action = $this->action();
        $this->failFirstAttempt($action, 'refused@example.com');
        $before = $this->counter->rows;

        $action->execute($this->form(), $this->submission(), ['mail' => 'victim@example.com'], new \Furan\FormBuilder\Api\ActionContext(false, 2, null, 2));

        $this->assertSame(1, $this->sent);
        $charged = array_diff_key($this->counter->rows, $before);
        // Per-address per-form, per-address and the paid marker; the store and form caps are not charged again.
        $this->assertCount(3, $charged);
        foreach (array_intersect_key($this->counter->rows, $before) as $key => $row) {
            $this->assertSame($before[$key], $row, $key);
        }
    }

    public function testItRefusesARetryToAnEditedAddressThatIsAlreadyCapped(): void
    {
        $action = $this->action();
        $action->execute($this->formWithId(6), $this->submissionFor(6), ['mail' => 'victim@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        $action->execute($this->formWithId(7), $this->submissionFor(7), ['mail' => 'victim@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        $action->execute($this->formWithId(8), $this->submissionFor(8), ['mail' => 'victim@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        $this->assertSame(3, $this->sent);
        $this->failFirstAttempt($action, 'refused@example.com');

        $action->execute($this->form(), $this->submission(), ['mail' => 'victim@example.com'], new \Furan\FormBuilder\Api\ActionContext(false, 2, null, 2));
        $action->execute($this->form(), $this->submission(), ['mail' => 'victim@example.com'], new \Furan\FormBuilder\Api\ActionContext(false, 2, null, 3));

        $this->assertSame(3, $this->sent);
    }

    public function testItReplacesTheReferencePlaceholderInTheAutoresponderSubjectAndBody(): void
    {
        $this->action()->execute($this->form([
            'autoresponder_subject' => 'Ref {{reference}}',
            'autoresponder_body' => "Your reference: {{reference}}\n{{var other}}",
        ]), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $vars = $this->first('setTemplateVars')[0];
        $this->assertSame('Ref 0194f3a0-1111-7222-8333-444455556666', $vars['subject']);
        $this->assertSame('Your reference: 0194f3a0-1111-7222-8333-444455556666<br />&#123;&#123;var other&#125;&#125;', $vars['body_html']);
    }

    public function testItThreadsTheAutoresponderUnderTheSubmissionRootId(): void
    {
        $headers = $this->threadHeaders = $this->createMock(\Furan\FormBuilder\Model\Thread\ThreadHeaders::class);
        $headers->method('rootId')->with(9, 2)->willReturn('ffb-9-0123456789abcdef@shop.test');
        $headers->expects($this->once())->method('apply')->with($this->anything(), 'ffb-9-0123456789abcdef@shop.test');

        $this->action()->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
    }

    public function testItSkipsTheAutoresponderWhenItsSendWhenConditionIsFalse(): void
    {
        $when = ['match' => 'all', 'rules' => [['field' => 'plan', 'operator' => 'equals', 'value' => 'pro']]];
        $payload = static fn (string $plan): array => [['name' => 'plan', 'label' => 'Plan', 'type' => 'text', 'value' => $plan]];
        $action = $this->action();
        $context = new \Furan\FormBuilder\Api\ActionContext();

        $action->execute($this->form(['autoresponder_when' => $when]), $this->submission($payload('free')), ['mail' => 'bob@example.com'], $context);
        $this->assertSame(0, $this->builders);

        // The skipped run did not use up the address's quota for the day.
        $action->execute($this->form(['autoresponder_when' => $when]), $this->submission($payload('pro')), ['mail' => 'bob@example.com'], $context);
        $this->assertSame(1, $this->sent);
        $action->execute($this->form(['autoresponder_when' => null]), $this->submission($payload('free')), ['mail' => 'other@example.com'], $context);
        $this->assertSame(2, $this->sent);
    }

    public function testItNeverIncludesSubmittedValuesInTheAutoresponder(): void
    {
        $this->action()->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com', 'secret' => 'TOPSECRET'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertStringNotContainsString('TOPSECRET', json_encode($this->first('setTemplateVars')));
        $this->assertArrayNotHasKey('values_html', $this->first('setTemplateVars')[0]);
        $this->assertArrayNotHasKey('values', $this->first('setTemplateVars')[0]);
    }

    public function testItPassesTheSubmissionReferenceDataToEverySubmissionEmail(): void
    {
        $this->action()->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame(
            ['id' => 9, 'created_at' => '2026-11-07 10:00:00', 'status' => 'New', 'reference' => '0194f3a0-1111-7222-8333-444455556666'],
            $this->first('setTemplateVars')[0]['submission']
        );
    }

    public function testItSkipsTheAutoresponderForAnInvalidAddress(): void
    {
        foreach (['nope', "bob@example.com\nBcc: x@example.com", '', null] as $bad) {
            $this->action()->execute($this->form(), $this->submission(), ['mail' => $bad], new \Furan\FormBuilder\Api\ActionContext());
        }
        $this->action()->execute($this->form(['autoresponder_enabled' => false]), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame(0, $this->sent);
        $this->assertSame(0, $this->builders);
    }

    public function testItThrottlesAutorespondersPerRecipient(): void
    {
        $action = $this->action();
        for ($i = 0; $i < 5; $i++) {
            $action->execute($this->form(), $this->submission(), ['mail' => 'Bob@Example.com'], new \Furan\FormBuilder\Api\ActionContext());
        }
        $action->execute($this->form(), $this->submission(), ['mail' => 'other@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        // One per address per form per day.
        $this->assertSame(2, $this->sent);
        $this->assertNotEmpty($this->logged);
        $this->assertStringNotContainsString('example.com', implode(' ', $this->logged));
        foreach (array_keys($this->counter->rows) as $key) {
            $this->assertStringStartsWith('ffb_ar_', $key);
            $this->assertStringNotContainsString('example', $key);
        }
    }

    public function testItSharesTheRecipientQuotaAcrossPlusAddressTags(): void
    {
        $action = $this->action();
        foreach (['bob@example.com', 'Bob+a@example.com', 'bob+b@Example.com', 'bob+c@example.com'] as $mail) {
            $action->execute($this->form(), $this->submission(), ['mail' => $mail], new \Furan\FormBuilder\Api\ActionContext());
        }

        $this->assertSame(1, $this->sent);
    }

    public function testItUsesAFreshTransportBuilderForEveryAutoresponder(): void
    {
        $action = $this->action();
        $action->execute($this->form(), $this->submission(), ['mail' => 'a@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        $action->execute($this->form(), $this->submission(), ['mail' => 'b@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame(2, $this->builders);
    }

    public function testItCapsAutorespondersPerFormPerHour(): void
    {
        $action = $this->action();
        for ($i = 0; $i < 60; $i++) {
            $action->execute($this->form(), $this->submission(), ['mail' => 'user' . $i . '@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        }

        $this->assertSame(50, $this->sent);
    }

    public function testItDoesNotUseTheRecipientQuotaWhenTheFormCapRefuses(): void
    {
        $action = $this->action();
        $this->counter->rows['ffb_ar_form_5'] = [50, time() + 3600];
        // Refused by the form cap: Bob's own quota must stay untouched.
        for ($i = 0; $i < 5; $i++) {
            $action->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        }
        $this->counter->rows['ffb_ar_form_5'] = [0, time() + 3600];
        $action->execute($this->form(), $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame(1, $this->sent);
    }
    public function testItCanBeQueuedOnlyWhenTheAutoresponderIsEnabled(): void
    {
        $this->assertTrue($this->action()->isApplicable($this->form()));
        $this->assertFalse($this->action()->isApplicable($this->form(['autoresponder_enabled' => false])));
    }

    public function testItBraceEncodesEveryTemplateValueSoDirectivesStayInert(): void
    {
        $form = $this->form([
            'autoresponder_subject' => 'Hi {{var x}}',
            'autoresponder_body' => '{{config path="web/secure/base_url"}}',
        ]);
        $this->action()->execute($form, $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $vars = array_values(array_filter($this->calls, fn ($c) => $c[0] === 'setTemplateVars'))[0][1][0];
        foreach ($vars as $name => $value) {
            $text = is_array($value) ? implode('', $value) : (string)$value;
            $this->assertStringNotContainsString('{', $text, $name);
            $this->assertStringNotContainsString('}', $text, $name);
        }
        $this->assertSame('Hi &#123;&#123;var x&#125;&#125;', $vars['subject']);
    }

    private int $dailyLimit = 0;
    private array $notices = [];

    private function notifier(): \Magento\Framework\Notification\NotifierInterface
    {
        $notifier = $this->createStub(\Magento\Framework\Notification\NotifierInterface::class);
        $notifier->method('addMajor')->willReturnCallback(function (string $title) use ($notifier) {
            $this->notices[] = $title;
            return $notifier;
        });
        return $notifier;
    }

    private function formWithId(int $id): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn($id);
        $form->method('getTitle')->willReturn('Contact');
        $form->method('getSettings')->willReturn([
            'autoresponder_enabled' => true, 'autoresponder_email_field' => 'mail',
            'autoresponder_subject' => 'Thanks', 'autoresponder_body' => 'Hi',
        ]);
        return $form;
    }

    private function submissionFor(int $formId): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getFormId')->willReturn($formId);
        $submission->method('getStoreId')->willReturn(2);
        return $submission;
    }

    public function testItSendsAsTheFormsStoreViewWhenTheSubmissionsStoreViewWasDeleted(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getStoreIds')->willReturn([7]);
        $form->method('getSettings')->willReturn([
            'autoresponder_enabled' => true, 'autoresponder_email_field' => 'mail',
            'autoresponder_subject' => 'Thanks', 'autoresponder_body' => 'Hi',
        ]);

        $this->action()->execute($form, $this->submission(), ['mail' => 'bob@example.com'], new \Furan\FormBuilder\Api\ActionContext());

        $this->assertSame('chosen_autoresponder_7', $this->first('setTemplateIdentifier')[0]);
        $this->assertSame(['area' => 'frontend', 'store' => 7], $this->first('setTemplateOptions')[0]);
        $this->assertSame(7, $this->first('setFromByScope')[1]);
    }

    public function testItSendsAnAddressAtMostThreeConfirmationsADayAcrossForms(): void
    {
        $action = $this->action();
        foreach ([11, 12, 13, 14, 15] as $formId) {
            $action->execute($this->formWithId($formId), $this->submissionFor($formId), ['mail' => 'victim@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        }

        $this->assertSame(3, $this->sent);
    }

    public function testItStopsAtTheStoreDailyLimitAndTellsTheAdminOnce(): void
    {
        $this->dailyLimit = 3;
        $action = $this->action();
        for ($i = 0; $i < 8; $i++) {
            $action->execute($this->form(), $this->submission(), ['mail' => 'person' . $i . '@example.com'], new \Furan\FormBuilder\Api\ActionContext());
        }

        $this->assertSame(3, $this->sent);
        $this->assertCount(1, $this->notices);
    }
}
