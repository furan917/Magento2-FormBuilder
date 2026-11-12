<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Account;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Account\Submission;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\ReplyPolicy;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Furan\FormBuilder\Model\Submission\StoreDisplayContext;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\Attributes\DataProvider;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionTest extends TestCase
{
    private array $params = [];

    public function testItHidesHiddenFieldsAndTheProductContextFromTheCustomer(): void
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'subject', 'label' => 'Subject'],
            ['kind' => 'field', 'type' => 'hidden', 'name' => 'utm', 'label' => 'UTM'],
            ['kind' => 'field', 'type' => 'file', 'name' => 'doc', 'label' => 'Doc'],
        ]]]];
        $payload = [
            ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'value' => 'Hello'],
            ['name' => 'utm', 'label' => 'UTM', 'type' => 'hidden', 'value' => 'secret-campaign'],
            ['name' => 'doc', 'label' => 'Doc', 'type' => 'file', 'value' => ['file' => 'furan_formbuilder/1/x', 'name' => 'a.pdf', 'size' => 3, 'mime' => 'application/pdf']],
            ['name' => 'old_hidden', 'label' => 'Old', 'type' => 'hidden', 'value' => 'removed-secret'],
            ['name' => 'old_text', 'label' => 'Old text', 'type' => 'text', 'value' => 'kept'],
        ];
        $row = [
            'submission_id' => '9', 'form_id' => '1', 'status' => '2', 'created_at' => '2026-01-01 10:00:00',
            'payload_json' => json_encode($payload), 'reference' => '0194f3a0-1111-7222-8333-444455556666',
        ];
        $reader = $this->createMock(SubmissionReader::class);
        $reader->method('find')->with(7, 9)->willReturn($row);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getTitle')->willReturn('Contact');
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(1)->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'id' ? '9' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $encoded = json_encode($view);
        $this->assertStringNotContainsString('secret-campaign', $encoded);
        $this->assertStringNotContainsString('removed-secret', $encoded);
        $this->assertStringNotContainsString('furan_formbuilder/1/x', $encoded);
        $this->assertStringNotContainsString('context', $encoded);
        $this->assertStringContainsString('Hello', $encoded);
        $this->assertStringContainsString('a.pdf', $encoded);
        $this->assertStringContainsString('kept', $encoded);
        $this->assertSame('Contact', $view['title']);
        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $view['reference']);
    }

    public function testItShowsDatesAndCountriesInTheLocaleOfTheStoreViewTheSubmissionWasMadeIn(): void
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'datetime', 'name' => 'when', 'label' => 'When'],
            ['kind' => 'field', 'type' => 'month', 'name' => 'month', 'label' => 'Month'],
            ['kind' => 'field', 'type' => 'country', 'name' => 'from', 'label' => 'From'],
            ['kind' => 'field', 'type' => 'address', 'name' => 'home', 'label' => 'Home'],
        ]]]];
        $payload = [
            ['name' => 'when', 'label' => 'When', 'type' => 'datetime', 'value' => '2026-10-05T09:15'],
            ['name' => 'month', 'label' => 'Month', 'type' => 'month', 'value' => '2026-09'],
            ['name' => 'from', 'label' => 'From', 'type' => 'country', 'value' => 'GB'],
            ['name' => 'home', 'label' => 'Home', 'type' => 'address', 'value' => ['street' => ['1 rue X'], 'city' => 'Lyon', 'country_id' => 'GB']],
        ];
        $reader = $this->createStub(SubmissionReader::class);
        $reader->method('find')->willReturn([
            'submission_id' => '9', 'form_id' => '1', 'status' => '0', 'store_id' => '2',
            'created_at' => '2026-01-01 10:00:00', 'payload_json' => json_encode($payload),
        ]);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn($schema);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'id' ? '9' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');
        $pool = FieldPools::adminView();

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $this->assertSame(
            [['5 oct. 2026, 09:15'], ['septembre 2026'], ['Royaume-Uni'], ['1 rue X, Lyon, Royaume-Uni']],
            array_column($view['groups'][0]['sections'][0]['entries'], 'values')
        );
    }

    public function testItTranslatesYesAndNoAnswers(): void
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'toggle', 'name' => 'on', 'label' => 'On'],
            ['kind' => 'field', 'type' => 'consent', 'name' => 'off', 'label' => 'Off'],
        ]]]];
        $payload = [
            ['name' => 'on', 'label' => 'On', 'type' => 'toggle', 'value' => 'yes'],
            ['name' => 'off', 'label' => 'Off', 'type' => 'consent', 'value' => ''],
        ];
        $reader = $this->createStub(SubmissionReader::class);
        $reader->method('find')->willReturn([
            'submission_id' => '9', 'form_id' => '1', 'status' => '0', 'store_id' => '2',
            'created_at' => '2026-01-01 10:00:00', 'payload_json' => json_encode($payload),
        ]);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn($schema);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'id' ? '9' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');
        $pool = FieldPools::adminView();
        $block = new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter($pool, new \Furan\FormBuilder\Model\Submission\AnswerDate()), $pool), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales());

        $view = \Furan\FormBuilder\Test\Unit\Helper\Translated::with('fr:', $block->getView(...));

        $this->assertSame([['fr:Yes'], ['fr:No']], array_column($view['groups'][0]['sections'][0]['entries'], 'values'));
    }

    public function testItShowsOnlyTheFileNameOfACustomFileTypeToTheCustomer(): void
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'subject', 'label' => 'Subject'],
            ['kind' => 'field', 'type' => 'hidden', 'name' => 'utm', 'label' => 'UTM'],
            ['kind' => 'field', 'type' => 'doc', 'name' => 'doc', 'label' => 'Doc'],
        ]]]];
        $payload = [
            ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'value' => 'Hello'],
            ['name' => 'utm', 'label' => 'UTM', 'type' => 'hidden', 'value' => 'secret-campaign'],
            ['name' => 'doc', 'label' => 'Doc', 'type' => 'doc', 'value' => ['file' => 'furan_formbuilder/1/x', 'name' => 'a.pdf', 'size' => 3, 'mime' => 'application/pdf']],
            ['name' => 'old_hidden', 'label' => 'Old', 'type' => 'hidden', 'value' => 'removed-secret'],
            ['name' => 'old_text', 'label' => 'Old text', 'type' => 'text', 'value' => 'kept'],
        ];
        $row = [
            'submission_id' => '9', 'form_id' => '1', 'status' => '2', 'created_at' => '2026-01-01 10:00:00',
            'payload_json' => json_encode($payload),
        ];
        $reader = $this->createMock(SubmissionReader::class);
        $reader->method('find')->with(7, 9)->willReturn($row);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getTitle')->willReturn('Contact');
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(1)->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'id' ? '9' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $encoded = json_encode($view);
        $this->assertSame(['a.pdf'], $view['groups'][0]['sections'][0]['entries'][1]['values']);
    }

    public function testItShowsTheFieldTypesTextForACustomStructuredTypeToTheCustomer(): void
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'point', 'name' => 'spot', 'label' => 'Spot'],
        ]]]];
        $payload = [['name' => 'spot', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '1', 'y' => '2']]];
        $row = [
            'submission_id' => '9', 'form_id' => '1', 'status' => '2', 'created_at' => '2026-01-01 10:00:00',
            'payload_json' => json_encode($payload),
        ];
        $reader = $this->createMock(SubmissionReader::class);
        $reader->method('find')->with(7, 9)->willReturn($row);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getTitle')->willReturn('Contact');
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(1)->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'id' ? '9' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $this->assertSame(['P[1|2]'], $view['groups'][0]['sections'][0]['entries'][0]['values']);
    }

    #[DataProvider('actionProvider')]
    public function testItOffersEditingOnlyWhileTheSubmissionIsNewOrInProgress(
        string $status,
        array $settings,
        bool $active,
        bool $canEdit,
        bool $canDelete
    ): void {
        $reader = $this->createStub(SubmissionReader::class);
        $reader->method('find')->willReturn([
            'submission_id' => '9', 'form_id' => '1', 'status' => $status,
            'created_at' => '2026-01-01 10:00:00', 'payload_json' => '[]',
        ]);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn($settings);
        $form->method('isActive')->willReturn($active);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $key === 'id' ? '9' : ($this->params[$key] ?? null));
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $this->assertSame($canEdit, $view['can_edit']);
        $this->assertSame($canDelete, $view['can_delete']);
    }

    private function gate(): ScheduleGate
    {
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(1800000000);
        return new ScheduleGate($clock);
    }

    #[DataProvider('scheduleProvider')]
    public function testItHidesTheEditButtonInMySubmissionsWhileTheFormIsClosed(
        array $schedule,
        bool $canEdit,
        bool $editClosed
    ): void {
        $reader = $this->createStub(SubmissionReader::class);
        $reader->method('find')->willReturn([
            'submission_id' => '9', 'form_id' => '1', 'status' => '0',
            'created_at' => '2026-01-01 10:00:00', 'payload_json' => '[]',
        ]);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn(['account_edit' => true] + $schedule);
        $form->method('isActive')->willReturn(true);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $key === 'id' ? '9' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $this->assertSame($canEdit, $view['can_edit']);
        $this->assertSame($editClosed, $view['edit_closed']);
        $this->assertTrue($view['can_delete'], 'Viewing and deleting stay available');
    }

    public function testItStillLetsACustomerViewAndDeleteASubmissionWhileTheFormIsClosed(): void
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'subject', 'label' => 'Subject'],
        ]]]];
        $reader = $this->createStub(SubmissionReader::class);
        $reader->method('find')->willReturn([
            'submission_id' => '9', 'form_id' => '1', 'status' => '0', 'store_id' => '4', 'created_at' => '2026-01-01 10:00:00',
            'payload_json' => json_encode([['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'value' => 'Hello']]),
        ]);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn(['account_delete' => true, 'close_at' => '2020-01-01T00:00:00Z']);
        $form->method('getSchema')->willReturn($schema);
        $form->method('isActive')->willReturn(true);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $key === 'id' ? '9' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $this->assertNotNull($view);
        $this->assertStringContainsString('Hello', json_encode($view['groups']));
        $this->assertTrue($view['can_delete']);
        $this->assertSame('open', $view['reply_state'], 'Replies follow the reply policy, not the schedule');
    }

    public static function scheduleProvider(): array
    {
        return [
            'closed' => [['close_at' => '2020-01-01T00:00:00Z'], false, true],
            'not yet open' => [['open_from' => '2099-01-01T00:00:00Z'], false, true],
            'open window' => [['open_from' => '2020-01-01T00:00:00Z', 'close_at' => '2099-01-01T00:00:00Z'], true, false],
            'no schedule' => [[], true, false],
        ];
    }

    public static function actionProvider(): array
    {
        return [
            'new, edit allowed' => ['0', ['account_edit' => true], true, true, true],
            'in progress, edit allowed' => ['1', ['account_edit' => true], true, true, true],
            'answered' => ['2', ['account_edit' => true], true, false, true],
            'closed' => ['3', ['account_edit' => true], true, false, true],
            'edit not enabled' => ['0', [], true, false, true],
            'inactive form' => ['0', ['account_edit' => true], false, false, true],
            'delete disabled' => ['0', ['account_delete' => false], true, false, false],
        ];
    }

    public function testItLeavesOutUnansweredQuestionsAndAPageTitledLikeTheFormAndShowsTheStatus(): void
    {
        $schema = ['pages' => [['title' => 'Contact', 'items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'subject', 'label' => 'Subject'],
            ['kind' => 'field', 'type' => 'text', 'name' => 'phone', 'label' => 'Phone'],
        ]]]];
        $payload = [
            ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'value' => 'Hello'],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'value' => ''],
        ];
        $reader = $this->createStub(SubmissionReader::class);
        $reader->method('find')->willReturn([
            'submission_id' => '9', 'form_id' => '1', 'status' => '1', 'created_at' => '2026-01-01 10:00:00',
            'payload_json' => json_encode($payload),
        ]);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getTitle')->willReturn('Contact');
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $key === 'id' ? '9' : ($this->params[$key] ?? null));
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $view = (new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $this->emptyThread(), $this->storeManager(), new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales()))->getView();

        $this->assertSame('', $view['groups'][0]['title']);
        $this->assertSame(['Subject'], array_column($view['groups'][0]['sections'][0]['entries'], 'label'));
        $this->assertSame('In progress', $view['status_label']);
        $this->assertFalse($view['one_response']);
    }

    private function locales(): StoreDisplayContext
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, mixed $store): string => $path === 'general/locale/code' && $store === 2 ? 'fr_FR' : 'en_GB'
        );
        return new StoreDisplayContext($config);
    }

    private function storeManager(string $storeName = 'My Shop'): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getFrontendName')->willReturn($storeName);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    private function emptyThread(): Messages
    {
        $messages = $this->createStub(Messages::class);
        $messages->method('pageForCustomer')->willReturn(['messages' => [], 'earlier' => false]);

        return $messages;
    }

    private function policy(bool $open = true, bool $full = false): ReplyPolicy&Stub
    {
        return $this->configurePolicy($this->createStub(ReplyPolicy::class), $open, $full);
    }

    private function assertedPolicy(bool $open = true, bool $full = false): ReplyPolicy&MockObject
    {
        return $this->configurePolicy($this->createMock(ReplyPolicy::class), $open, $full);
    }

    /**
     * @template T of ReplyPolicy&Stub
     * @param T $policy
     * @return T
     */
    private function configurePolicy(ReplyPolicy&Stub $policy, bool $open = true, bool $full = false): ReplyPolicy&Stub
    {
        $policy->method('customerRepliesOpen')->willReturnCallback(
            // Always the submission's own store (4), never the store being browsed.
            static fn (array $settings, ?int $storeId): bool => $storeId === 4 && $open
        );
        $policy->method('isFull')->willReturn($full);

        return $policy;
    }

    private function threadBlock(
        string $status,
        Messages $messages,
        string $storeName = 'My Shop',
        ?ReplyPolicy $policy = null
    ): Submission {
        $reader = $this->createMock(SubmissionReader::class);
        $reader->method('find')->with(7, 9)->willReturn([
            'submission_id' => '9', 'form_id' => '1', 'status' => $status, 'store_id' => '4',
            'created_at' => '2026-01-01 10:00:00', 'payload_json' => '[]',
        ]);
        $form = $this->createStub(FormInterface::class);
        $form->method('isActive')->willReturn(true);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturn($form);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $key === 'id' ? '9' : ($this->params[$key] ?? null));
        $storeManager = $this->storeManager($storeName);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        return new Submission($context, $reader, $repository, new AnswerView(new ValueFormatter(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\AnswerDate()), FieldPools::withFiles()), new Json(), $session, $messages, $storeManager, new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()), $policy ?? $this->policy(), new SubmissionStatus(), $this->gate(), new SubmissionPolicy(), $this->locales());
    }

    public function testItShowsTheConversationToTheOwner(): void
    {
        $thread = [['message_id' => 1, 'author' => 'admin', 'author_name' => 'My Shop', 'body' => 'Hello', 'created_at' => 'x']];
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('pageForCustomer')->with(9, 'My Shop', null)
            ->willReturn(['messages' => $thread, 'earlier' => false]);

        $view = $this->threadBlock('2', $messages)->getView();

        $this->assertSame($thread, $view['thread']);
        $this->assertFalse($view['earlier']);
        $this->assertNull($view['before']);
        $this->assertTrue($view['can_reply']);
        $this->assertSame('open', $view['reply_state']);
    }

    public function testItNeverShowsAdminUserNamesToTheCustomer(): void
    {
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->never())->method('page');
        $messages->method('pageForCustomer')->willReturn(['messages' => [], 'earlier' => false]);

        $this->threadBlock('2', $messages)->getView();
    }

    public function testItHidesTheReplyFormOnceTheSubmissionIsClosed(): void
    {
        $messages = $this->createStub(Messages::class);
        $messages->method('pageForCustomer')->willReturn(['messages' => [], 'earlier' => false]);
        $policy = $this->createMock(ReplyPolicy::class);
        $policy->method('customerRepliesOpen')->willReturn(true);
        $policy->expects($this->never())->method('isFull');

        $view = $this->threadBlock('3', $messages, 'My Shop', $policy)->getView();
        $this->assertFalse($view['can_reply']);
        $this->assertSame('closed', $view['reply_state']);
    }

    public function testItRendersStoreRepliesAsMarkdownAndCustomerRepliesAsPlainText(): void
    {
        $block = (new \ReflectionClass(Submission::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(Submission::class, 'renderer');
        $property->setValue($block, new MessageRenderer(EscaperFactory::create(), new MiniMarkdown()));

        $this->assertSame('<p><em>hi</em></p>', $block->renderMessage(['author' => 'admin', 'body' => '*hi*']));
        $this->assertSame('*hi* &lt;b&gt;', $block->renderMessage(['author' => 'customer', 'body' => '*hi* <b>']));
    }

    public function testItPagesBackToEarlierMessagesByMessageId(): void
    {
        $this->params = ['before' => '120'];
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('pageForCustomer')->with(9, 'My Shop', 120)
            ->willReturn(['messages' => [], 'earlier' => true]);

        $view = $this->threadBlock('1', $messages)->getView();

        $this->assertTrue($view['earlier']);
        $this->assertSame(120, $view['before']);
    }

    #[DataProvider('badBefore')]
    public function testItIgnoresABeforeParameterThatIsNotDigits(mixed $before): void
    {
        $this->params = ['before' => $before];
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('pageForCustomer')->with(9, 'My Shop', null)
            ->willReturn(['messages' => [], 'earlier' => false]);

        $this->threadBlock('1', $messages)->getView();
    }

    public static function badBefore(): array
    {
        return [['12abc'], [['5']], ['-3'], ['0'], ['12345678901'], ['']];
    }

    public function testItClosesTheReplyBoxWhenRepliesAreOffForTheForm(): void
    {
        $messages = $this->createStub(Messages::class);
        $messages->method('pageForCustomer')->willReturn(['messages' => [['message_id' => 1]], 'earlier' => false]);
        $policy = $this->assertedPolicy(false);
        $policy->expects($this->never())->method('isFull');

        $view = $this->threadBlock('2', $messages, 'My Shop', $policy)->getView();

        $this->assertFalse($view['can_reply']);
        $this->assertSame('off', $view['reply_state']);
    }

    public function testItReplacesTheReplyBoxWhenTheThreadIsFull(): void
    {
        $messages = $this->createStub(Messages::class);
        $messages->method('pageForCustomer')->willReturn(['messages' => [], 'earlier' => false]);

        $view = $this->threadBlock('2', $messages, 'My Shop', $this->policy(true, true))->getView();

        $this->assertFalse($view['can_reply']);
        $this->assertSame('full', $view['reply_state']);
    }
}
