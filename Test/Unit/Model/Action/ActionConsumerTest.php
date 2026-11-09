<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Action;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\ActionRetrySchedulerInterface;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Action\ActionConsumer;
use Furan\FormBuilder\Model\Action\ActionExecutor;
use Furan\FormBuilder\Model\Action\SubmissionActionPool;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Model\Submission\SubmissionStub;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionConsumerTest extends TestCase
{
    private SubmissionRepositoryInterface&Stub $submissions;
    private FormRepositoryInterface&Stub $forms;
    private Translator&Stub $translator;
    private LoggerInterface&Stub $logger;
    /** @var array<string, mixed> */
    private array $pooled = [];
    /** @var int[] */
    private array $scopes = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?FormRepositoryInterface $forms = null, ?SubmissionRepositoryInterface $submissions = null, ?Translator $translator = null): void
    {
        $this->submissions = $submissions ?? $this->createStub(SubmissionRepositoryInterface::class);
        $this->forms = $forms ?? $this->createStub(FormRepositoryInterface::class);
        $this->translator = $translator ?? $this->createStub(Translator::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->pooled = [];
    }

    private function consumer(): ActionConsumer
    {
        $scope = $this->createStub(StoreScope::class);
        $scope->method('run')->willReturnCallback(function (int $storeId, callable $work) {
            $this->scopes[] = $storeId;
            return $work();
        });

        return new ActionConsumer(
            $this->submissions,
            $this->forms,
            $this->translator,
            FieldPools::withFiles(),
            new Json(),
            $this->logger,
            new SubmissionActionPool($this->pooled),
            new ActionExecutor($this->createStub(ActionRetrySchedulerInterface::class), $this->logger),
            $scope,
            new SubmissionStore($this->createStub(StoreManagerInterface::class))
        );
    }

    private function stored(): SubmissionStub
    {
        $submission = (new SubmissionStub())->setFormId(7)->setStoreId(2)->setCustomerId(5)->setPayload([
            ['name' => 'email', 'type' => 'email', 'value' => 'a@b.test'],
            ['name' => 'cv', 'type' => 'file', 'value' => ['file' => 'x', 'name' => 'cv.pdf']],
        ]);
        $submission->id = 41;
        $this->submissions->method('getById')->with(41)->willReturn($submission);
        $form = $this->createStub(FormInterface::class);
        $this->forms->method('getById')->with(7)->willReturn($form);
        $this->translator->method('localize')->with($form, 2)->willReturn($form);

        return $submission;
    }

    private function action(bool $applicable = true, bool $supports = true): DeferrableActionInterface&Stub
    {
        return $this->configureAction($this->createStub(DeferrableActionInterface::class), $applicable, $supports);
    }

    private function assertedAction(bool $applicable = true, bool $supports = true): DeferrableActionInterface&MockObject
    {
        return $this->configureAction($this->createMock(DeferrableActionInterface::class), $applicable, $supports);
    }

    /**
     * @template T of DeferrableActionInterface&Stub
     * @param T $action
     * @return T
     */
    private function configureAction(DeferrableActionInterface&Stub $action, bool $applicable = true, bool $supports = true): DeferrableActionInterface&Stub
    {
        $action->method('isApplicable')->willReturn($applicable);
        $action->method('supports')->willReturn($supports);

        return $action;
    }

    public function testItRunsTheQueuedActionAsTheSubmissionsStoreWithTheStoredAnswersAndAttempt(): void
    {
        $forms = $this->createMock(FormRepositoryInterface::class);
        $submissions = $this->createMock(SubmissionRepositoryInterface::class);
        $translator = $this->createMock(Translator::class);
        $this->fixture(forms: $forms, submissions: $submissions, translator: $translator);
        $submission = $this->stored();
        $action = $this->assertedAction();
        $action->expects($this->once())->method('execute')->with(
            $this->anything(),
            $submission,
            ['email' => 'a@b.test'],
            $this->callback(static fn (ActionContext $c): bool => $c->attempt === 3 && $c->isEdit === true
                && $c->storeId === 2 && $c->customerId === 5)
        );
        $other = $this->assertedAction();
        $other->expects($this->never())->method('execute');
        $this->pooled = ['crm' => $action, 'other' => $other];

        $this->consumer()->process('{"id":41,"action":"crm","attempt":3,"edit":true}');

        $this->assertSame([2], $this->scopes);
    }

    public function testItRunsAsTheFormsStoreViewWhenTheSubmissionsStoreViewWasDeleted(): void
    {
        $submission = (new SubmissionStub())->setFormId(7)->setStoreId(null)->setPayload([]);
        $submission->id = 41;
        $this->submissions->method('getById')->willReturn($submission);
        $form = $this->createStub(FormInterface::class);
        $form->method('getStoreIds')->willReturn([6]);
        $this->forms->method('getById')->willReturn($form);
        $translator = $this->createMock(Translator::class);
        $translator->expects($this->once())->method('localize')->with($form, 6)->willReturn($form);
        $this->translator = $translator;
        $action = $this->assertedAction();
        $action->expects($this->once())->method('execute')->with(
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->callback(static fn (ActionContext $c): bool => $c->storeId === 6)
        );
        $this->pooled = ['crm' => $action];

        $this->consumer()->process('{"id":41,"action":"crm"}');

        $this->assertSame([6], $this->scopes);
    }

    public function testItSkipsAQueuedActionWhoseSubmissionWasDeleted(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->submissions->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $logger->expects($this->never())->method('error');

        $this->consumer()->process('{"id":41,"action":"crm","attempt":1,"edit":false}');
    }

    public function testItDropsAndLogsAnUnknownActionKey(): void
    {
        $forms = $this->createMock(FormRepositoryInterface::class);
        $submissions = $this->createMock(SubmissionRepositoryInterface::class);
        $translator = $this->createMock(Translator::class);
        $this->fixture(forms: $forms, submissions: $submissions, translator: $translator);
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->stored();
        $this->pooled = [];
        $logger->expects($this->once())->method('warning');

        $this->consumer()->process('{"id":41,"action":"gone","attempt":1,"edit":false}');
    }

    public function testItSkipsAnActionThatIsNoLongerApplicableOrSupported(): void
    {
        $forms = $this->createMock(FormRepositoryInterface::class);
        $submissions = $this->createMock(SubmissionRepositoryInterface::class);
        $translator = $this->createMock(Translator::class);
        $this->fixture(forms: $forms, submissions: $submissions, translator: $translator);
        $this->stored();
        $off = $this->assertedAction(false);
        $off->expects($this->never())->method('execute');
        $noEdit = $this->assertedAction(true, false);
        $noEdit->expects($this->never())->method('execute');
        $this->pooled = ['off' => $off, 'noedit' => $noEdit];

        $this->consumer()->process('{"id":41,"action":"off","attempt":1,"edit":false}');
        $this->consumer()->process('{"id":41,"action":"noedit","attempt":1,"edit":true}');
    }

    public function testItNeverThrowsSoABadMessageCannotBlockTheQueue(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->consumer()->process('not json');
    }
}
