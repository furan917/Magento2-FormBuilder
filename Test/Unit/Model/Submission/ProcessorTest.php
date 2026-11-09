<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Test\Unit\Helper\ModelFixtures;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterfaceFactory;
use Furan\FormBuilder\Api\SubmissionActionInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Spam\SpamGuard;
use Furan\FormBuilder\Model\Spam\TokenService;
use Furan\FormBuilder\Model\Submission\Processor;
use Furan\FormBuilder\Model\Survey\AlreadyAnsweredException;
use Furan\FormBuilder\Model\Survey\Respondents;
use Furan\FormBuilder\Model\Submission\ValidationResult;
use Furan\FormBuilder\Model\Submission\Validator;
use Furan\FormBuilder\Model\Upload\FilesNormalizer;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Upload\FileValidator;
use Furan\FormBuilder\Model\Submission\EditToken;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Submission\NotEditableException;
use Furan\FormBuilder\Model\Submission\SubmissionUpdater;
use Furan\FormBuilder\Model\Submission\SummaryBuilder;
use Magento\Customer\Model\Session;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Http\ClientIp;
use Furan\FormBuilder\Model\Submission\SubmissionInputFactory;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Furan\FormBuilder\Model\Upload\UploadBudget;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessorTest extends TestCase
{
    private SpamGuard&Stub $spam;
    private TokenService&Stub $tokens;
    private Validator&Stub $validator;
    private FileStorage&Stub $storage;
    private FileValidator&Stub $fileValidator;
    private FileRegistry&Stub $registry;
    private SubmissionRepositoryInterface&Stub $repository;
    private ManagerInterface&Stub $events;
    private Session&Stub $session;
    private ClientIp&Stub $remote;
    private RateLimiter&Stub $rateLimiter;
    private UploadBudget&Stub $budget;
    private bool $ceilingHit = false;
    private bool $budgetLeft = true;
    private LoggerInterface&Stub $logger;
    private Store&Stub $store;
    private SpamResult $requestSpam;
    private SpamResult $valuesSpam;
    private array $valuesCalls = [];
    private array $order = [];
    private DeploymentConfig&MockObject $deploymentConfig;
    private SubmissionStub $submission;
    private Respondents&Stub $respondents;
    private Journal&Stub $journal;
    private SeriesRegistry&Stub $seriesRegistry;
    private SubmissionUpdater&Stub $updater;
    private EditToken&Stub $editToken;
    private \Furan\FormBuilder\Model\Action\ActionQueue&Stub $actionQueue;
    private string $editParam = '';
    private bool $inTransaction = false;
    private int $built = 0;
    private \Furan\FormBuilder\Model\Webhook\SubmissionDeliveries&Stub $deliveries;
    private array $extraTypes = [];
    private \Furan\FormBuilder\Model\Prefill\PrefillService&Stub $prefill;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?\Furan\FormBuilder\Model\Action\ActionQueue $actionQueue = null, ?\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries $deliveries = null, ?EditToken $editToken = null, ?ManagerInterface $events = null, ?FileValidator $fileValidator = null, ?Journal $journal = null, ?LoggerInterface $logger = null, ?\Furan\FormBuilder\Model\Prefill\PrefillService $prefill = null, ?RateLimiter $rateLimiter = null, ?FileRegistry $registry = null, ?SubmissionRepositoryInterface $repository = null, ?Respondents $respondents = null, ?SeriesRegistry $seriesRegistry = null, ?Session $session = null, ?SpamGuard $spam = null, ?FileStorage $storage = null, ?TokenService $tokens = null, ?SubmissionUpdater $updater = null, ?Validator $validator = null): void
    {
        $this->requestSpam = SpamResult::pass();
        $this->valuesSpam = SpamResult::pass();
        $this->spam = $spam ?? $this->createStub(SpamGuard::class);
        $this->spam->method('check')->willReturnCallback(fn (): SpamResult => $this->requestSpam);
        $this->spam->method('checkValues')->willReturnCallback(function (...$args): SpamResult {
            $this->valuesCalls[] = $args;
            $this->order[] = 'values';
            return $this->valuesSpam;
        });
        $this->tokens = $tokens ?? $this->createStub(TokenService::class);
        $this->tokens->method('consume')->willReturn(true);
        $this->validator = $validator ?? $this->createStub(Validator::class);
        $this->storage = $storage ?? $this->createStub(FileStorage::class);
        $this->fileValidator = $fileValidator ?? $this->createStub(FileValidator::class);
        $this->registry = $registry ?? $this->createStub(FileRegistry::class);
        $this->registry->method('saveWith')->willReturnCallback(function (array $paths, callable $save): void {
            $this->inTransaction = true;
            try {
                $this->registry->attach($paths, $save());
            } finally {
                $this->inTransaction = false;
            }
        });
        $this->deliveries = $deliveries ?? $this->createStub(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->journal = $journal ?? $this->createStub(Journal::class);
        $this->seriesRegistry = $seriesRegistry ?? $this->createStub(SeriesRegistry::class);
        $this->updater = $updater ?? $this->createStub(SubmissionUpdater::class);
        $this->editToken = $editToken ?? $this->createStub(EditToken::class);
        $this->actionQueue = $actionQueue ?? $this->createStub(\Furan\FormBuilder\Model\Action\ActionQueue::class);
        $this->respondents = $respondents ?? $this->createStub(Respondents::class);
        $this->respondents->method('customerKey')->willReturnCallback(static fn(int $id): string => 'c' . $id);
        $this->respondents->method('emailKey')->willReturnCallback(static fn(string $e): string => 'e' . strtolower($e));
        $this->repository = $repository ?? $this->createStub(SubmissionRepositoryInterface::class);
        $this->events = $events ?? $this->createStub(ManagerInterface::class);
        $this->session = $session ?? $this->createStub(Session::class);
        $this->remote = $this->createStub(ClientIp::class);
        $this->rateLimiter = $rateLimiter ?? $this->createStub(RateLimiter::class);
        $this->rateLimiter->method('formCeilingReached')->willReturnCallback(fn (): bool => $this->ceilingHit);
        $this->budget = $this->createStub(UploadBudget::class);
        $this->budget->method('reserve')->willReturnCallback(fn (): bool => $this->budgetLeft);
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->deploymentConfig->method('get')->with('crypt/key')->willReturn('k0 k1');
        $this->store = $this->createStub(Store::class);
        $this->store->method('getId')->willReturn(2);
        $this->store->method('getBaseUrl')->willReturn('https://shop.test/');
        $this->submission = new SubmissionStub();
        $this->prefill = $prefill ?? $this->createStub(\Furan\FormBuilder\Model\Prefill\PrefillService::class);
    }

    private function executor(): \Furan\FormBuilder\Model\Action\ActionExecutor
    {
        return new \Furan\FormBuilder\Model\Action\ActionExecutor(
            $this->createStub(\Furan\FormBuilder\Api\ActionRetrySchedulerInterface::class),
            $this->logger
        );
    }

    /** A mock action that runs for every context unless told otherwise. */
    private function action(string $interface = SubmissionActionInterface::class, bool $supportsEdits = true): SubmissionActionInterface&Stub
    {
        return $this->configureAction($this->createStub($interface), $interface, $supportsEdits);
    }

    private function assertedAction(string $interface = SubmissionActionInterface::class, bool $supportsEdits = true): SubmissionActionInterface&MockObject
    {
        return $this->configureAction($this->createMock($interface), $interface, $supportsEdits);
    }

    /**
     * @template T of SubmissionActionInterface&Stub
     * @param T $action
     * @return T
     */
    private function configureAction(SubmissionActionInterface&Stub $action, string $interface = SubmissionActionInterface::class, bool $supportsEdits = true): SubmissionActionInterface&Stub
    {
        $action->method('supports')->willReturnCallback(
            static fn (\Furan\FormBuilder\Api\ActionContext $c): bool => $supportsEdits || !$c->isEdit
        );
        return $action;
    }

    private function gate(): \Furan\FormBuilder\Model\Form\ScheduleGate
    {
        $clock = $this->createStub(\Magento\Framework\Stdlib\DateTime\DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(1800000000);
        return new \Furan\FormBuilder\Model\Form\ScheduleGate($clock);
    }

    private function processor(array $actions = [], ?Pool $pool = null, ?StoreManagerInterface $storeManager = null): Processor
    {
        if ($storeManager === null) {
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($this->store);
        }
        $pool ??= new Pool(['file' => new File(), 'text' => new Text()] + $this->extraTypes);
        $runner = $this->actionRunner($actions);
        $files = $this->submissionFiles($pool);

        return new Processor(
            $this->spam, $this->validator,
            $this->events, $storeManager,
            $this->logger, $this->tokens, $pool,
            \Furan\FormBuilder\Test\Unit\Helper\TranslatorFactory::create(
                $this->createStub(\Furan\FormBuilder\Model\Url\RedirectValidator::class),
                $pool
            ),
            new \Furan\FormBuilder\Model\Access\FormAccess(),
            new \Furan\FormBuilder\Model\Prefill\LockedValues($this->prefill, \Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools::builtIn()),
            new \Furan\FormBuilder\Model\Submission\SubmissionEditor($this->updater, $this->repository, $runner, $files, $this->logger),
            new \Furan\FormBuilder\Model\Customer\Impersonation($this->session), $runner, $files, $this->submissionBuilder(),
            new \Furan\FormBuilder\Model\Submission\EditTargetResolver($this->editToken, $this->respondents, $this->updater),
            $this->rateLimiter, $this->gate(), $this->writer()
        );
    }

    private function submissionFiles(Pool $pool): \Furan\FormBuilder\Model\Upload\SubmissionFiles
    {
        return new \Furan\FormBuilder\Model\Upload\SubmissionFiles($this->storage, $this->fileValidator, $this->budget, $pool, $this->logger);
    }

    private function submissionBuilder(): \Furan\FormBuilder\Model\Submission\SubmissionBuilder
    {
        return new \Furan\FormBuilder\Model\Submission\SubmissionBuilder(
            $this->factory(),
            new EmailHasher($this->deploymentConfig),
            new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles())),
            new \Furan\FormBuilder\Model\Http\IpAnonymizer()
        );
    }

    private function writer(): \Furan\FormBuilder\Model\Submission\NewSubmissionWriter
    {
        return new \Furan\FormBuilder\Model\Submission\NewSubmissionWriter(
            $this->registry,
            $this->repository,
            $this->seriesRegistry,
            $this->journal,
            new \Furan\FormBuilder\Model\Survey\RespondentRecorder($this->respondents),
            $this->deliveries,
            $this->logger
        );
    }

    private function actionRunner(array $actions = []): \Furan\FormBuilder\Model\Action\ActionRunner
    {
        return new \Furan\FormBuilder\Model\Action\ActionRunner(
            new \Furan\FormBuilder\Model\Action\SubmissionActionPool($actions),
            $this->actionQueue,
            $this->executor(),
            new \Furan\FormBuilder\Model\Submission\SubmissionStore($this->createStub(StoreManagerInterface::class))
        );
    }

    private function form(array $settings = [], ?array $schema = null): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getSettings')->willReturn($settings);
        $form->method('getSchema')->willReturn($schema ?? $this->schema());
        return $form;
    }

    private function schema(): array
    {
        $f = static fn(string $id, string $type, string $name, string $label): array => [
            'id' => $id, 'kind' => 'field', 'type' => $type, 'name' => $name, 'label' => $label,
        ];
        return ['pages' => [['id' => 'p_aaaa', 'items' => [
            $f('f_name1', 'text', 'name', 'Name'),
            ['id' => 's_sec11', 'kind' => 'section', 'items' => [
                $f('f_mail1', 'email', 'email', 'Email'),
                $f('f_cv001', 'file', 'cv', 'CV'),
            ]],
            $f('f_msg01', 'textarea', 'msg', 'Message'),
        ]]]];
    }

    /** Built by the real factory from the session and client address mocks, as the storefront controller does. */
    private function fromHttp(Http $request): SubmissionInput
    {
        return (new SubmissionInputFactory(
            $this->session,
            $this->storeManager(),
            $this->remote,
            new FilesNormalizer(),
            new \Furan\FormBuilder\Model\Customer\Impersonation($this->session)
        ))->fromHttp($request);
    }

    private function request(array $fields = []): SubmissionInput
    {
        $request = $this->createStub(Http::class);
        $edit = $this->editParam;
        $request->method('getParam')->willReturnCallback(
            static fn(string $k, $d = null) => match ($k) {
                'fields' => $fields,
                'ffb_token' => 'tok123',
                'ffb_edit' => $edit !== '' ? $edit : $d,
                default => $d,
            }
        );
        return $this->fromHttp($request);
    }

    private function valid(array $values, array $files = []): void
    {
        $this->validator->method('validate')->willReturn(new ValidationResult($values, [], $files));
    }

    public function testItReturnsAFakeSuccessForHoneypotSubmissionsWithoutStoring(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $validator = $this->createMock(Validator::class);
        $this->fixture(repository: $repository, validator: $validator);
        $this->requestSpam = SpamResult::silent('honeypot');
        $repository->expects($this->never())->method('save');
        $validator->expects($this->never())->method('validate');
        $action = $this->assertedAction();
        $action->expects($this->never())->method('execute');

        $result = $this->processor([$action])->process(
            $this->form(['success_message' => 'Thanks!']),
            $this->request()
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->getHttpCode());
        $this->assertSame('Thanks!', $result->getMessage());
    }

    public function testItRejectsWithTheMessageGivenByASpamCheck(): void
    {
        $validator = $this->createMock(Validator::class);
        $this->fixture(validator: $validator);
        $this->requestSpam = SpamResult::reject('blocked', new Phrase('Blocked by policy.'), 403);
        $validator->expects($this->never())->method('validate');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(403, $result->getHttpCode());
        $this->assertSame('Blocked by policy.', $result->getMessage());
    }

    public function testItKeepsTheSpamRefusalMessageWhenTheCheckGivesNone(): void
    {
        $this->requestSpam = SpamResult::reject('blocked');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertSame(400, $result->getHttpCode());
        $this->assertSame('Please refresh the page and try again.', $result->getMessage());
    }

    public function testItReturnsTheNormalSuccessResponseAndStoresNothingOnASilentResult(): void
    {
        $actionQueue = $this->createMock(\Furan\FormBuilder\Model\Action\ActionQueue::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(actionQueue: $actionQueue, repository: $repository);
        $this->valid(['name' => 'Ann']);
        $this->valuesSpam = SpamResult::silent('bot');
        $repository->expects($this->never())->method('save');
        $actionQueue->expects($this->never())->method('publish');
        $this->events->method('dispatch')->willReturnCallback(function (string $name): void {
            $this->assertNotSame('furan_formbuilder_submission_after', $name);
        });
        $action = $this->assertedAction();
        $action->expects($this->never())->method('execute');

        $result = $this->processor([$action])->process($this->form(['success_message' => 'Thanks!']), $this->request());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->getHttpCode());
        $this->assertSame('Thanks!', $result->getMessage());
    }

    public function testItRunsValuesSpamChecksAfterValidationWithNormalizedValues(): void
    {
        $this->validator->method('validate')->willReturnCallback(function (): ValidationResult {
            $this->order[] = 'validate';
            return new ValidationResult(['name' => 'Ann'], [], []);
        });
        $this->events->method('dispatch')->willReturnCallback(function (string $name): void {
            $this->order[] = $name;
        });
        $form = $this->form();
        $request = $this->request();

        $this->processor()->process($form, $request);

        $this->assertSame([[$form, ['name' => 'Ann'], $request]], $this->valuesCalls);
        $this->assertSame(
            ['validate', 'furan_formbuilder_submission_validate_after', 'values'],
            array_slice($this->order, 0, 3)
        );
    }

    public function testItKeepsTheExistingResponsesForHoneypotTimeTrapAndRateLimit(): void
    {
        $processor = $this->processor();
        $this->requestSpam = SpamResult::silent('honeypot');
        $honeypot = $processor->process($this->form(), $this->request());
        $this->requestSpam = SpamResult::reject('token');
        $trap = $processor->process($this->form(), $this->request());
        $this->requestSpam = SpamResult::reject('rate_limit', new Phrase('Too many submissions. Please wait a few minutes and try again.'), 429);
        $limit = $processor->process($this->form(), $this->request());

        $this->assertSame([true, 200], [$honeypot->isSuccess(), $honeypot->getHttpCode()]);
        $this->assertSame([false, 400], [$trap->isSuccess(), $trap->getHttpCode()]);
        $this->assertSame([false, 429], [$limit->isSuccess(), $limit->getHttpCode()]);
    }

    public function testItStoresNoUploadedFileWhenAValuesSpamCheckRejectsOrDropsTheSubmission(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(repository: $repository, storage: $storage);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3, 'type' => ''];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $storage->expects($this->never())->method('store');
        $repository->expects($this->never())->method('save');
        $processor = $this->processor();

        $this->valuesSpam = SpamResult::reject('bot', new Phrase('No.'));
        $rejected = $processor->process($this->form(), $this->request());
        $this->valuesSpam = SpamResult::silent('bot');
        $dropped = $processor->process($this->form(), $this->request());

        $this->assertFalse($rejected->isSuccess());
        $this->assertSame('No.', $rejected->getMessage());
        $this->assertTrue($dropped->isSuccess());
    }

    public function testItConsumesTheTokenOnASilentResult(): void
    {
        $this->requestSpam = SpamResult::silent('honeypot');
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->once())->method('consume')->with('tok123')->willReturn(true);
        $this->tokens = $tokens;

        $this->assertTrue($this->processor()->process($this->form(), $this->request())->isSuccess());
    }

    public function testItReturns400ForAnInvalidTokenAnd429WhenRateLimited(): void
    {
        $processor = $this->processor();

        $this->requestSpam = SpamResult::reject('token');
        $token = $processor->process($this->form(), $this->request());
        $this->assertFalse($token->isSuccess());
        $this->assertSame(400, $token->getHttpCode());
        $this->assertSame('Please refresh the page and try again.', $token->getMessage());

        $this->requestSpam = SpamResult::reject('rate_limit', new \Magento\Framework\Phrase('Too many submissions. Please wait a few minutes and try again.'), 429);
        $rate = $processor->process($this->form(), $this->request());
        $this->assertSame(429, $rate->getHttpCode());
        $this->assertSame(
            'Too many submissions. Please wait a few minutes and try again.',
            $rate->getMessage()
        );
    }

    public function testItReturns422WithFieldErrorsWhenValidationFails(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->validator->method('validate')
            ->willReturn(new ValidationResult([], ['name' => 'Required']));
        $repository->expects($this->never())->method('save');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertSame(422, $result->getHttpCode());
        $this->assertSame('Please fix the highlighted fields.', $result->getMessage());
        $this->assertSame(['name' => 'Required'], $result->getErrors());
    }

    public function testItStoresUploadedFilesAndRecordsTheirMetadataInThePayload(): void
    {
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(storage: $storage);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3, 'type' => ''];
        $this->valid(['name' => 'Ann', 'email' => 'a@b.co', 'msg' => ''], ['cv' => $upload]);
        $meta = ['file' => 'furan_formbuilder/7/2026/11/aa.pdf', 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'application/pdf'];
        $storage->expects($this->once())->method('store')
            ->with($upload, $this->callback(fn(array $f): bool => $f['name'] === 'cv'), 7)
            ->willReturn($meta);

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Ann'],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'a@b.co'],
                ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => $meta],
                ['name' => 'msg', 'label' => 'Message', 'type' => 'textarea', 'value' => ''],
            ],
            $this->submission->getPayload()
        );
    }

    public function testItReturns422WhenFileStorageFailsAndRemovesEarlierFiles(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(repository: $repository, storage: $storage);
        $schema = $this->schema();
        $schema['pages'][0]['items'][] = ['id' => 'f_cv002', 'kind' => 'field', 'type' => 'file',
            'name' => 'cv2', 'label' => 'CV2'];
        $up = ['name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid([], ['cv' => $up, 'cv2' => $up]);
        $this->storage->method('store')->willReturnOnConsecutiveCalls(
            ['file' => 'furan_formbuilder/7/2026/11/aa.pdf', 'name' => 'a', 'size' => 1, 'mime' => 'x'],
            $this->throwException(new LocalizedException(__('This file type is not allowed.')))
        );
        $storage->expects($this->once())->method('delete')->with('furan_formbuilder/7/2026/11/aa.pdf');
        $repository->expects($this->never())->method('save');

        $result = $this->processor()->process($this->form([], $schema), $this->request());

        $this->assertSame(422, $result->getHttpCode());
        $this->assertSame(['cv2' => 'This file type is not allowed.'], $result->getErrors());
    }

    public function testItSavesTheSubmissionWithTheLiteralAnswersAsItsSummary(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->valid([
            'name' => '<b>Ann</b>', 'email' => 'a@b.co', 'msg' => str_repeat('x', 400),
        ]);
        $this->customer(1, 'c@x.co');
        $repository->expects($this->once())->method('save')->with($this->submission);

        $this->processor()->process($this->form(), $this->request());

        $summary = $this->submission->getSummary();
        $this->assertStringStartsWith('<b>Ann</b> · a@b.co · xxx', $summary);
        $this->assertSame(255, mb_strlen($summary));
        $this->assertSame(7, $this->submission->getFormId());
        $this->assertSame(2, $this->submission->getStoreId());
        $this->assertSame(5, $this->submission->getCustomerId());
    }

    private function customer(int $groupId, string $email): void
    {
        $data = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $data->method('getEmail')->willReturn($email);
        $this->session->method('isLoggedIn')->willReturn(true);
        $this->session->method('getCustomerId')->willReturn(5);
        $this->session->method('getCustomerGroupId')->willReturn($groupId);
        $this->session->method('getCustomerData')->willReturn($data);
    }

    public function testItStoresTheCustomerGroupOfALoggedInCustomer(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->customer(4, 'c@x.co');

        $this->processor()->process($this->form(), $this->request());

        $this->assertSame(4, $this->submission->getCustomerGroupId());
    }

    public function testItStoresGroupZeroForGuests(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->session->method('getCustomerGroupId')->willReturn(1);

        $this->processor()->process($this->form(), $this->request());

        $this->assertSame(0, $this->submission->getCustomerGroupId());
    }

    public function testItUsesTheLoggedInCustomerEmailBeforeTheFormEmailField(): void
    {
        $this->valid(['name' => 'Ann', 'email' => 'form@x.co']);
        $this->customer(1, ' Cust@X.co ');
        $hasher = new EmailHasher($this->deploymentConfig);

        $this->processor()->process($this->form(['email_field' => 'email']), $this->request());

        $expected = $hasher->hash('cust@x.co');
        $this->assertSame($expected['hash'], $this->submission->getEmailHash());
    }

    public function testItStoresTheKeyVersionNextToTheEmailHash(): void
    {
        $this->valid(['name' => 'Ann', 'email' => 'Form@X.co']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $hasher = new EmailHasher($this->deploymentConfig);

        $this->processor()->process($this->form(['autoresponder_email_field' => 'email']), $this->request());

        $this->assertSame($hasher->hash('form@x.co')['hash'], $this->submission->getEmailHash());
        $this->assertSame(1, $this->submission->getEmailHashVersion());
    }

    public function testItStoresNoEmailHashForAGuestWithoutAnEmailAnswer(): void
    {
        $this->valid(['name' => 'Ann', 'email' => '']);
        $this->session->method('isLoggedIn')->willReturn(false);

        $this->processor()->process($this->form(['email_field' => 'email']), $this->request());
        $this->assertNull($this->submission->getEmailHash());
        $this->assertNull($this->submission->getEmailHashVersion());
    }

    public function testItStoresStatusNewForEverySubmission(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->submission->setStatus(2);

        $this->processor()->process($this->form(), $this->request());

        $this->assertSame(0, $this->submission->getStatus());
    }

    public function testItTruncatesTheIpAddressOnlyWhenStoringIpsIsEnabled(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->remote->method('address')->willReturnOnConsecutiveCalls(
            '203.0.113.77', '2001:db8:abcd:12:1:2:3:4', '203.0.113.77'
        );
        $processor = $this->processor();

        $processor->process($this->form(['store_ip' => true]), $this->request());
        $this->assertSame('203.0.113.0', $this->submission->getRemoteIp());

        $processor->process($this->form(['store_ip' => true]), $this->request());
        $this->assertSame('2001:db8:abcd::', $this->submission->getRemoteIp());

        $this->submission->setRemoteIp(null);
        $processor->process($this->form(['store_ip' => false]), $this->request());
        $this->assertNull($this->submission->getRemoteIp());
    }

    public function testItRunsActionsAndLogsActionFailuresWithoutFailing(): void
    {
        $events = $this->createMock(ManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(events: $events, logger: $logger);
        $this->valid(['name' => 'Ann']);
        $bad = $this->action();
        $bad->method('execute')->willThrowException(new \RuntimeException('smtp down'));
        $good = $this->assertedAction();
        $good->expects($this->once())->method('execute')
            ->with($this->anything(), $this->submission, ['name' => 'Ann']);
        $logger->expects($this->once())->method('error');
        $events->expects($this->atLeastOnce())->method('dispatch')->willReturnCallback(
            function (string $name, array $d = []): void {
                if ($name === 'furan_formbuilder_submission_after') {
                    $this->assertTrue(isset($d['form'], $d['submission'], $d['values']));
                }
            }
        );

        $result = $this->processor([$bad, 'junk', $good])->process($this->form(), $this->request());

        $this->assertTrue($result->isSuccess());
    }

    private function onlyValidateEventsAllowed(): void
    {
        $this->events->method('dispatch')->willReturnCallback(function (string $name): void {
            $this->assertSame('furan_formbuilder_submission_validate_after', $name);
        });
    }

    public function testItDispatchesSubmissionAfterWithTheSavedSubmissionId(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->storesWithId(41);
        $seen = [];
        $this->events->method('dispatch')->willReturnCallback(function (string $name, array $d = []) use (&$seen): void {
            if ($name === 'furan_formbuilder_submission_after') {
                $seen = ['id' => $d['submission']->getId(), 'submission_id' => $d['submission_id'] ?? null];
            }
        });

        $this->processor()->process($this->form(), $this->request());

        $this->assertSame(['id' => 41, 'submission_id' => 41], $seen);
    }

    public function testItStillReturnsSuccessAndRunsActionsWhenASubmissionAfterObserverThrows(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->valid(['name' => 'Ann']);
        $this->events->method('dispatch')->willReturnCallback(function (string $name): void {
            if ($name === 'furan_formbuilder_submission_after') {
                throw new \RuntimeException('observer broke');
            }
        });
        $action = $this->assertedAction();
        $action->expects($this->once())->method('execute');
        $logger->expects($this->atLeastOnce())->method('error');

        $result = $this->processor([$action])->process($this->form(), $this->request());

        $this->assertTrue($result->isSuccess());
    }

    public function testItLetsAValidateAfterObserverAddAnErrorThatBlocksTheSubmit(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->valid(['name' => 'Ann']);
        $repository->expects($this->never())->method('save');
        $this->events->method('dispatch')->willReturnCallback(function (string $name, array $d = []): void {
            if ($name === 'furan_formbuilder_submission_validate_after') {
                $this->assertSame(['name' => 'Ann'], $d['values']->getData());
                $d['errors']->setData('name', 'Not on the list');
            }
        });

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(422, $result->getHttpCode());
        $this->assertSame(['name' => 'Not on the list'], $result->getErrors());
        $this->assertSame('Please fix the highlighted fields.', $result->getMessage());
    }

    public function testItShowsAValidateAfterErrorForAnUnknownFieldAsTheGeneralMessage(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->events->method('dispatch')->willReturnCallback(function (string $name, array $d = []): void {
            if ($name === 'furan_formbuilder_submission_validate_after') {
                $d['errors']->setData('_form', 'Too many entries today');
            }
        });

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertSame(422, $result->getHttpCode());
        $this->assertSame('Too many entries today', $result->getMessage());
    }

    public function testItDispatchesValidateAfterForEditsToo(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $this->fixture(editToken: $editToken);
        $this->valid(['name' => 'Ann']);
        $fired = 0;
        $this->events->method('dispatch')->willReturnCallback(function (string $name) use (&$fired): void {
            $fired += $name === 'furan_formbuilder_submission_validate_after' ? 1 : 0;
        });
        $settings = $this->editLink();

        $this->processor()->process($this->form($settings), $this->request());

        $this->assertSame(1, $fired);
    }

    private function deferrable(bool $applicable = true): \Furan\FormBuilder\Api\DeferrableActionInterface&Stub
    {
        $action = $this->action(\Furan\FormBuilder\Api\DeferrableActionInterface::class);
        $action->method('isApplicable')->willReturn($applicable);
        return $action;
    }

    private function assertedDeferrable(bool $applicable = true): \Furan\FormBuilder\Api\DeferrableActionInterface&MockObject
    {
        $action = $this->assertedAction(\Furan\FormBuilder\Api\DeferrableActionInterface::class);
        $action->method('isApplicable')->willReturn($applicable);
        return $action;
    }

    private function storesWithId(int $id): void
    {
        $this->repository->method('save')->willReturnCallback(function ($submission) use ($id) {
            $submission->id = $id;
            return $submission;
        });
    }

    public function testItPublishesOneQueueMessagePerDeferrableActionWhenBackgroundActionsAreOn(): void
    {
        $actionQueue = $this->createMock(\Furan\FormBuilder\Model\Action\ActionQueue::class);
        $this->fixture(actionQueue: $actionQueue);
        $this->valid(['name' => 'Ann']);
        $this->storesWithId(41);
        $notify = $this->assertedDeferrable();
        $notify->expects($this->never())->method('execute');
        $crm = $this->assertedDeferrable();
        $crm->expects($this->never())->method('execute');
        $inline = $this->assertedAction();
        $inline->expects($this->once())->method('execute');
        $published = [];
        $actionQueue->expects($this->exactly(2))->method('publish')
            ->willReturnCallback(function (int $id, string $key, int $attempt, bool $edit) use (&$published): bool {
                $this->assertFalse($this->inTransaction, 'published inside the submit transaction');
                $published[] = [$id, $key, $attempt, $edit];
                return true;
            });

        $result = $this->processor(['notification' => $notify, 'plain' => $inline, 'crm' => $crm])
            ->process($this->form(), $this->request());

        $this->assertTrue($result->isSuccess());
        $this->assertSame([[41, 'notification', 1, false], [41, 'crm', 1, false]], $published);
    }

    public function testItRunsDeferrableActionsInlineWhenBackgroundActionsAreOffOrPublishingFails(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->storesWithId(41);
        $declined = $this->assertedDeferrable();
        $declined->expects($this->once())->method('execute');
        $queued = $this->assertedDeferrable();
        $queued->expects($this->never())->method('execute');
        $this->actionQueue->method('publish')->willReturnCallback(
            static fn (int $id, string $key): bool => $key === 'queued'
        );

        $this->processor(['declined' => $declined, 'queued' => $queued])->process($this->form(), $this->request());
    }

    public function testItNeverQueuesASubmissionThatIsNotStoredSoNoAnswersGoIntoTheQueue(): void
    {
        $actionQueue = $this->createMock(\Furan\FormBuilder\Model\Action\ActionQueue::class);
        $this->fixture(actionQueue: $actionQueue);
        $this->valid(['name' => 'Ann']);
        $mail = $this->assertedDeferrable();
        $mail->expects($this->once())->method('execute');
        $actionQueue->expects($this->never())->method('publish');

        $this->processor([$mail])->process($this->form(['store_submissions' => false]), $this->request());
    }

    public function testItQueuesNothingWhenNoActionWouldRun(): void
    {
        $actionQueue = $this->createMock(\Furan\FormBuilder\Model\Action\ActionQueue::class);
        $this->fixture(actionQueue: $actionQueue);
        $this->valid(['name' => 'Ann']);
        $this->storesWithId(41);
        $actionQueue->expects($this->never())->method('publish');

        $this->processor([$this->deferrable(false)])->process($this->form(), $this->request());
    }

    public function testItPassesAnActionContextWithStoreCustomerEditFlagAndAttempt(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->storesWithId(41);
        $this->customer(1, 'c@x.co');
        $action = $this->assertedAction();
        $action->expects($this->once())->method('execute')->with(
            $this->anything(),
            $this->anything(),
            ['name' => 'Ann'],
            $this->callback(static fn (\Furan\FormBuilder\Api\ActionContext $c): bool => $c->storeId === 2
                && $c->customerId === 5 && $c->isEdit === false && $c->attempt === 1)
        );

        $this->processor([$action])->process($this->form(), $this->request());
    }

    public function testItWritesAJournalRowWhenANewSubmissionIsStored(): void
    {
        $journal = $this->createMock(Journal::class);
        $this->fixture(journal: $journal);
        $this->valid(['name' => 'Ann']);
        $specs = ['name' => ['kind' => 9, 'bucket_step' => null]];
        $this->seriesRegistry->method('specsFor')->willReturn($specs);
        $journal->expects($this->once())->method('add')
            ->willReturnCallback(function ($submission, array $given) use ($specs): void {
                $this->assertTrue($this->inTransaction);
                $this->assertSame($this->submission, $submission);
                $this->assertSame($specs, $given);
            });

        $this->assertTrue($this->processor()->process($this->form(), $this->request())->isSuccess());
    }

    public function testItKeepsJournalingWhenAnalyticsIsDisabled(): void
    {
        $journal = $this->createMock(Journal::class);
        $this->fixture(journal: $journal);
        $this->valid(['name' => 'Ann']);
        $journal->expects($this->once())->method('add');

        $this->processor()->process($this->form(['analytics_enabled' => false]), $this->request());
    }

    public function testItWritesNoJournalWhenTheFormDoesNotStoreSubmissions(): void
    {
        $journal = $this->createMock(Journal::class);
        $seriesRegistry = $this->createMock(SeriesRegistry::class);
        $this->fixture(journal: $journal, seriesRegistry: $seriesRegistry);
        $this->valid(['name' => 'Ann']);
        $journal->expects($this->never())->method('add');
        $seriesRegistry->expects($this->never())->method('specsFor');

        $this->processor()->process($this->form(['store_submissions' => false]), $this->request());
    }

    public function testItJournalsTheInsertWithTheSameUtcCreatedAtItStoresOnTheSubmission(): void
    {
        $this->valid(['name' => 'Ann']);
        $saved = null;
        $this->repository->method('save')->willReturnCallback(function () use (&$saved) {
            $saved = $this->submission->getCreatedAt();
            return $this->submission;
        });
        $journaled = null;
        $this->journal->method('add')->willReturnCallback(function ($submission) use (&$journaled): void {
            $journaled = $submission->getCreatedAt();
        });

        $this->processor()->process($this->form(), $this->request());

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$saved);
        $this->assertSame($saved, $journaled);
    }

    public function testItResolvesSeriesSpecsBeforeOpeningTheSubmitTransaction(): void
    {
        $seriesRegistry = $this->createMock(SeriesRegistry::class);
        $this->fixture(seriesRegistry: $seriesRegistry);
        $this->valid(['name' => 'Ann']);
        $seriesRegistry->expects($this->once())->method('specsFor')
            ->willReturnCallback(function (int $formId, array $fields): array {
                $this->assertFalse($this->inTransaction);
                $this->assertSame(7, $formId);
                $this->assertSame(['name', 'email', 'cv', 'msg'], array_column($fields, 'name'));
                return [];
            });

        $this->processor()->process($this->form(), $this->request());
    }

    public function testItAssignsAReferenceWhenASubmissionIsCreated(): void
    {
        $this->valid(['name' => 'Ann']);
        $saved = null;
        $this->repository->method('save')->willReturnCallback(function () use (&$saved) {
            $saved = $this->submission->getReference();
            return $this->submission;
        });

        $this->processor()->process($this->form(), $this->request());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string)$saved);
    }

    public function testItAssignsAReferenceToASubmissionThatIsNotStored(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->valid(['name' => 'Ann']);
        $repository->expects($this->never())->method('save');
        $seen = null;
        $action = $this->action();
        $action->method('execute')->willReturnCallback(function ($form, $submission) use (&$seen): void {
            $seen = $submission->getReference();
        });

        $this->processor([$action])->process($this->form(['store_submissions' => false]), $this->request());

        $this->assertNotSame('', (string)$seen);
    }

    public function testItDoesNotSaveWhenStoringSubmissionsIsDisabled(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->valid(['name' => 'Ann']);
        $repository->expects($this->never())->method('save');
        $action = $this->assertedAction();
        $action->expects($this->once())->method('execute');

        $result = $this->processor([$action])->process(
            $this->form(['store_submissions' => false]),
            $this->request()
        );

        $this->assertTrue($result->isSuccess());
    }

    public function testItReturnsTheSuccessMessageAndRedirect(): void
    {
        $this->valid(['name' => 'Ann']);
        $processor = $this->processor();

        $default = $processor->process($this->form(), $this->request());
        $this->assertSame('Thank you, your form has been sent.', $default->getMessage());
        $this->assertNull($default->getRedirect());

        $relative = $processor->process(
            $this->form(['success_message' => 'Done', 'success_redirect' => '/thanks']),
            $this->request()
        );
        $this->assertSame('Done', $relative->getMessage());
        $this->assertSame('https://shop.test/thanks', $relative->getRedirect());

        $absolute = $processor->process(
            $this->form(['success_redirect' => 'https://shop.test/ok']),
            $this->request()
        );
        $this->assertSame('https://shop.test/ok', $absolute->getRedirect());
    }

    public function testItReturns500AndRemovesStoredFilesOnUnexpectedErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(logger: $logger, storage: $storage);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $this->storage->method('store')->willReturn(
            ['file' => 'furan_formbuilder/7/2026/11/aa.pdf', 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'x']
        );
        $this->repository->method('save')->willThrowException(new \RuntimeException('db gone'));
        $storage->expects($this->once())->method('delete')->with('furan_formbuilder/7/2026/11/aa.pdf');
        $logger->expects($this->once())->method('error');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertSame(500, $result->getHttpCode());
        $this->assertSame('Something went wrong. Please try again.', $result->getMessage());
    }

    public function testItDoesNotWriteFilesToDiskWhenStoringSubmissionsIsDisabled(): void
    {
        $fileValidator = $this->createMock(FileValidator::class);
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(fileValidator: $fileValidator, storage: $storage);
        $tmp = tempnam(sys_get_temp_dir(), 'ffb');
        file_put_contents($tmp, 'abc');
        $upload = ['name' => 'dir/cv.pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => 3];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $storage->expects($this->never())->method('store');
        $fileValidator->expects($this->once())->method('validate')->willReturn(null);
        $this->fileValidator->method('detectMime')->willReturn('application/pdf');
        $this->storage->method('displayName')->willReturn('cv.pdf');

        $result = $this->processor()->process($this->form(['store_submissions' => false]), $this->request());
        unlink($tmp);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            ['name' => 'cv.pdf', 'size' => 3, 'mime' => 'application/pdf'],
            $this->submission->getPayload()[1]['value']
        );
    }

    public function testItReturns422WhenFileValidationFailsWithStoringDisabled(): void
    {
        $upload = ['name' => 'cv.exe', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid([], ['cv' => $upload]);
        $this->fileValidator->method('validate')->willReturn('This file type is not allowed.');

        $result = $this->processor()->process($this->form(['store_submissions' => false]), $this->request());

        $this->assertSame(['cv' => 'This file type is not allowed.'], $result->getErrors());
    }

    public function testItReadsFieldsWithGetParamAndFilesThroughTheFilesNormalizer(): void
    {
        $validator = $this->createMock(Validator::class);
        $this->fixture(validator: $validator);
        $request = $this->createMock(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $k, $d = null) => match ($k) {
                'fields' => ['name' => 'Ann'],
                'ffb_token' => 'tok123',
                default => $d,
            }
        );
        $request->method('getFiles')->with('files')->willReturn([
            'cv' => ['name' => 'a.pdf', 'type' => '', 'tmp_name' => '/tmp/x', 'error' => '0', 'size' => '3'],
            'junk' => 'nope',
        ]);
        $validator->expects($this->once())->method('validate')->with(
            $this->anything(),
            ['name' => 'Ann'],
            ['cv' => ['name' => 'a.pdf', 'type' => '', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3]],
            2
        )->willReturn(new ValidationResult(['name' => 'Ann']));

        $this->processor()->process($this->form(), $this->fromHttp($request));
    }

    public function testItTreatsNonArrayFieldsAsEmpty(): void
    {
        $validator = $this->createMock(Validator::class);
        $this->fixture(validator: $validator);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $k) => $k === 'ffb_token' ? 'tok123' : ($k === 'ffb_edit' ? null : 'oops')
        );
        $validator->expects($this->once())->method('validate')
            ->with($this->anything(), [], [], 2)->willReturn(new ValidationResult());

        $this->processor()->process($this->form(), $this->fromHttp($request));
    }

    public function testItConsumesTheTokenBeforeProcessingSoAParallelReplayFails(): void
    {
        $this->valid(['name' => 'Ann']);
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->exactly(2))->method('consume')->with('tok123')
            ->willReturnOnConsecutiveCalls(true, false);
        $tokens->expects($this->never())->method('release');
        $this->tokens = $tokens;
        $processor = $this->processor();

        $this->assertTrue($processor->process($this->form(), $this->request())->isSuccess());
        $replay = $processor->process($this->form(), $this->request());
        $this->assertSame(400, $replay->getHttpCode());
        $this->assertSame('Please refresh the page and try again.', $replay->getMessage());
    }

    public function testItReleasesTheTokenWhenTheSubmissionFailsValidation(): void
    {
        $this->validator->method('validate')->willReturn(new ValidationResult([], ['name' => 'Required']));
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->once())->method('consume')->with('tok123')->willReturn(true);
        $tokens->expects($this->once())->method('release')->with('tok123');
        $this->tokens = $tokens;
        $this->assertSame(422, $this->processor()->process($this->form(), $this->request())->getHttpCode());
    }

    public function testItReturnsAGenericUploadErrorAndLogsFilesystemFailures(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $this->storage->method('store')->willThrowException(
            new FileSystemException(__('Cannot write /var/www/pub/media/secret'))
        );
        $logger->expects($this->once())->method('error');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertSame(422, $result->getHttpCode());
        $this->assertSame(['cv' => 'The file could not be uploaded.'], $result->getErrors());
    }

    public function testItLinksStoredFilesToTheSavedSubmission(): void
    {
        $registry = $this->createMock(FileRegistry::class);
        $this->fixture(registry: $registry);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $this->storage->method('store')->willReturn(
            ['file' => 'furan_formbuilder/7/2026/11/' . str_repeat('a', 32), 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'x']
        );
        $this->repository->method('save')->willReturnCallback(function ($submission) {
            $submission->id = 41;
            return $submission;
        });
        $registry->expects($this->once())->method('attach')
            ->with(['furan_formbuilder/7/2026/11/' . str_repeat('a', 32)], 41);

        $this->assertTrue($this->processor()->process($this->form(), $this->request())->isSuccess());
    }

    public function testItChecksEveryFileBeforeWritingAny(): void
    {
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(storage: $storage);
        $schema = $this->schema();
        $schema['pages'][0]['items'][] = ['id' => 'f_cv002', 'kind' => 'field', 'type' => 'file',
            'name' => 'cv2', 'label' => 'CV2'];
        $up = ['name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid([], ['cv' => $up, 'cv2' => ['name' => 'b.php'] + $up]);
        $this->fileValidator->method('validate')->willReturnCallback(
            fn(array $upload): ?string => $upload['name'] === 'b.php' ? 'This file type is not allowed.' : null
        );
        $storage->expects($this->never())->method('store');

        $result = $this->processor()->process($this->form([], $schema), $this->request());

        $this->assertSame(['cv2' => 'This file type is not allowed.'], $result->getErrors());
    }

    public function testItProcessesASubmissionFromASubmissionInputWithoutARequest(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $tokens = $this->createMock(TokenService::class);
        $validator = $this->createMock(Validator::class);
        $this->fixture(repository: $repository, tokens: $tokens, validator: $validator);
        $input = new SubmissionInput(fields: ['name' => 'Ann'], formToken: 'tok123', storeId: 2, clientIp: '203.0.113.77');
        $validator->expects($this->once())->method('validate')
            ->with($this->anything(), ['name' => 'Ann'], [], 2)
            ->willReturn(new ValidationResult(['name' => 'Ann']));
        $tokens->expects($this->once())->method('consume')->with('tok123');
        $repository->expects($this->once())->method('save')->with($this->submission);

        $result = $this->processor()->process($this->form(['store_ip' => true]), $input);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('203.0.113.0', $this->submission->getRemoteIp());
    }

    public function testItPassesTheSubmissionInputToTheSpamGuardInBothStages(): void
    {
        $input = new SubmissionInput(fields: ['name' => 'Ann'], formToken: 'tok123', storeId: 2);
        $this->valid(['name' => 'Ann']);
        $this->spam = $this->createMock(SpamGuard::class);
        $this->spam->expects($this->once())->method('check')->with($input, $this->anything())->willReturn(SpamResult::pass());
        $this->spam->expects($this->once())->method('checkValues')->with($this->anything(), ['name' => 'Ann'], $input)
            ->willReturn(SpamResult::pass());

        $this->assertTrue($this->processor()->process($this->form(), $input)->isSuccess());
    }

    public function testItJudgesTheRequestSizeFromTheSubmissionInput(): void
    {
        $spam = $this->createMock(SpamGuard::class);
        $this->fixture(spam: $spam);
        $this->fileValidator->method('maxBytes')->willReturn(5 * 1048576);
        $spam->expects($this->never())->method('check');
        $big = new SubmissionInput(formToken: 'tok123', contentLength: 7 * 1048576, storeId: 2);

        $this->assertSame(413, $this->processor()->process($this->form(), $big)->getHttpCode());
    }

    public function testItReadsTheCustomerAndStoreFromTheSubmissionInput(): void
    {
        $session = $this->createMock(Session::class);
        $this->fixture(session: $session);
        $this->valid(['name' => 'Ann']);
        $session->expects($this->never())->method($this->anything());
        $this->prefill->method('valuesFor')->willReturn([]);
        $input = new SubmissionInput(
            fields: ['name' => 'Ann'],
            formToken: 'tok123',
            customerId: 12,
            customerGroupId: 4,
            customerEmail: 'Cust@X.co',
            storeId: 2
        );
        $hasher = new EmailHasher($this->deploymentConfig);

        $this->processor()->process($this->form(), $input);

        $this->assertSame(12, $this->submission->getCustomerId());
        $this->assertSame(4, $this->submission->getCustomerGroupId());
        $this->assertSame(2, $this->submission->getStoreId());
        $this->assertSame($hasher->hash('cust@x.co')['hash'], $this->submission->getEmailHash());
    }

    public function testItRefusesAnImpersonatedSurveyAnswerFromTheSubmissionInput(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->valid(['name' => 'Ann']);
        $repository->expects($this->never())->method('save');
        $input = new SubmissionInput(formToken: 'tok123', customerId: 12, impersonated: true, storeId: 2);

        $result = $this->processor()->process($this->form(['survey_mode' => 'once']), $input);

        $this->assertSame(403, $result->getHttpCode());
    }

    public function testItRefusesARequestLargerThanTheFormsFileLimitsAllowBeforeAnyWork(): void
    {
        $spam = $this->createMock(SpamGuard::class);
        $tokens = $this->createMock(TokenService::class);
        $this->fixture(spam: $spam, tokens: $tokens);
        $this->fileValidator->method('maxBytes')->willReturn(5 * 1048576);
        $request = $this->createStub(Http::class);
        $request->method('getServer')->willReturnCallback(
            static fn (string $k) => $k === 'CONTENT_LENGTH' ? (string)(7 * 1048576) : null
        );
        $spam->expects($this->never())->method('check');
        $tokens->expects($this->never())->method('consume');

        $result = $this->processor()->process($this->form(), $this->fromHttp($request));

        $this->assertSame(413, $result->getHttpCode());
        $this->assertSame('The files you chose are too large.', $result->getMessage());
    }

    private const CLOSED = ['close_at' => '2020-01-01T00:00:00Z', 'closed_message' => 'We are closed.'];

    public function testItRefusesASubmitOutsideTheSchedule(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->valid(['name' => 'Ann']);
        $repository->expects($this->never())->method('save');

        $result = $this->processor()->process($this->form(self::CLOSED), $this->request());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(403, $result->getHttpCode());
        $this->assertSame('We are closed.', $result->getMessage());

        $notYet = ['open_from' => '2099-01-01T00:00:00Z', 'not_open_message' => 'Not yet.'];
        $result = $this->processor()->process($this->form($notYet), $this->request());
        $this->assertSame(403, $result->getHttpCode());
        $this->assertSame('Not yet.', $result->getMessage());
    }

    public function testItRefusesAClosedFormBeforeConsumingTheToken(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $spam = $this->createMock(SpamGuard::class);
        $tokens = $this->createMock(TokenService::class);
        $validator = $this->createMock(Validator::class);
        $this->fixture(rateLimiter: $rateLimiter, spam: $spam, tokens: $tokens, validator: $validator);
        $spam->expects($this->never())->method('check');
        $tokens->expects($this->never())->method('consume');
        $tokens->expects($this->never())->method('release');
        $rateLimiter->expects($this->never())->method('formCeilingReached');
        $validator->expects($this->never())->method('validate');

        $this->assertSame(403, $this->processor()->process($this->form(self::CLOSED), $this->request())->getHttpCode());
    }

    public function testItRefusesASurveyEditWhileTheFormIsClosed(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(repository: $repository, updater: $updater);
        $this->customer(1, 'c@x.co');
        $this->respondents->method('find')->willReturn(['submission_id' => 4]);
        $updater->expects($this->never())->method('update');
        $repository->expects($this->never())->method('save');

        $result = $this->processor()->process($this->form(self::CLOSED + ['survey_mode' => 'edit']), $this->request());

        $this->assertSame(403, $result->getHttpCode());
        $this->assertSame('We are closed.', $result->getMessage());
    }

    public function testItRefusesAMySubmissionsEditWhileTheFormIsClosed(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(editToken: $editToken, updater: $updater);
        $settings = $this->editor() + self::CLOSED;
        $this->editParam = 'edit.token';
        $editToken->expects($this->never())->method('verify');
        $updater->expects($this->never())->method('update');

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertSame(403, $result->getHttpCode());
    }

    public function testItSumsTheUploadedFilesWhenAChunkedRequestHasNoContentLength(): void
    {
        $spam = $this->createMock(SpamGuard::class);
        $this->fixture(spam: $spam);
        $this->fileValidator->method('maxBytes')->willReturn(5 * 1048576);
        $request = $this->createMock(Http::class);
        $request->method('getServer')->willReturn(null);
        $request->method('getFiles')->with('files')->willReturn(['cv' => [
            'name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 7 * 1048576,
        ]]);
        $spam->expects($this->never())->method('check');

        $this->assertSame(413, $this->processor()->process($this->form(), $this->fromHttp($request))->getHttpCode());
    }

    public function testItAcceptsARequestWithinTheFormsFileLimits(): void
    {
        $this->fileValidator->method('maxBytes')->willReturn(5 * 1048576);
        $request = $this->createStub(Http::class);
        $request->method('getServer')->willReturn((string)(6 * 1048576 - 1));
        $request->method('getParam')->willReturn(null);
        $this->valid(['name' => 'Ann']);

        $this->assertNotSame(413, $this->processor()->process($this->form(), $this->fromHttp($request))->getHttpCode());
    }

    public function testAFailureAfterSavingKeepsTheSubmissionsFiles(): void
    {
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(storage: $storage);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $this->storage->method('store')->willReturn(
            ['file' => 'furan_formbuilder/7/2026/11/' . str_repeat('a', 32), 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'x']
        );
        $this->events->method('dispatch')->willThrowException(new \RuntimeException('observer failed'));
        $storage->expects($this->never())->method('delete');

        $this->assertSame(500, $this->processor()->process($this->form(), $this->request())->getHttpCode());
    }

    public function testVisitorsGetTheirStoreViewsTextsWhileAnswersKeepTheDefaultLabels(): void
    {
        $schema = ['pages' => [['id' => 'p_aaaa', 'title' => 'Page', 'items' => [[
            'id' => 'f_name1', 'kind' => 'field', 'type' => 'text', 'name' => 'name', 'label' => 'Name',
            'rules' => [['rule' => 'min_length', 'value' => 3, 'message' => 'Too short']],
        ]]], ['id' => 'p_bbbb', 'title' => 'Two', 'condition' => ['match' => 'all', 'rules' => []], 'items' => [[
            'id' => 's_cccc', 'kind' => 'section', 'title' => 'Sec', 'condition' => null, 'skip' => ['match' => 'any', 'rules' => []],
            'items' => [['id' => 'f_other', 'kind' => 'field', 'type' => 'text', 'name' => 'other', 'label' => 'Other']],
        ]]]]];
        $form = ModelFixtures::model(\Furan\FormBuilder\Model\Form::class, 7);
        $form->setSchema($schema)->setSettings(['success_message' => 'Thanks'])->setTranslations([2 => [
            'form' => ['success_message' => ['t' => 'Merci', 's' => 'Thanks']],
            'items' => ['f_name1' => [
                'label' => ['t' => 'Nom', 's' => 'Name'],
                'rules' => ['min_length' => ['message' => ['t' => 'Trop court', 's' => 'Too short']]],
            ]],
        ]]);
        $validated = null;
        $this->validator->method('validate')->willReturnCallback(function (array $schema) use (&$validated) {
            $validated = $schema;
            return new ValidationResult(['name' => 'Ann'], [], []);
        });
        $processor = $this->processor(pool: new Pool(['text' => new Text()]), storeManager: $this->storeManager());

        $result = $processor->process($form, $this->request());

        $field = $validated['pages'][0]['items'][0];
        $this->assertSame('Trop court', $field['rules'][0]['message'], 'Server errors match the browser');
        $this->assertSame($schema['pages'][1], $validated['pages'][1], 'Page and section metadata survive the message rewrite');
        $this->assertSame('Name', $field['label']);
        $this->assertSame('Merci', $result->getMessage());
        $this->assertSame('Name', $this->submission->getPayload()[0]['label']);
    }

    public function testItRefusesSubmissionsThatBreakTheAccessRulesWith403(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $storage = $this->createMock(FileStorage::class);
        $tokens = $this->createMock(TokenService::class);
        $this->fixture(repository: $repository, storage: $storage, tokens: $tokens);
        $this->valid(['name' => 'Ann']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $storage->expects($this->never())->method('store');
        $repository->expects($this->never())->method('save');
        $tokens->expects($this->once())->method('release')->with('tok123');

        $result = $this->processor()->process($this->form(['access_login_required' => true]), $this->request());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(403, $result->getHttpCode());
    }

    public function testItRefusesALoggedInCustomerOutsideTheAllowedGroupsWith403(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->customer(3, 'c@x.co');

        $result = $this->processor()->process($this->form(['access_groups' => [1, 2]]), $this->request());

        $this->assertSame(403, $result->getHttpCode());
    }

    private function lockedSchema(): array
    {
        $f = static fn(string $id, string $name, string $prefill): array => [
            'id' => $id, 'kind' => 'field', 'type' => 'text', 'name' => $name, 'label' => $name,
            'prefill' => $prefill, 'locked' => true,
        ];
        return ['pages' => [['id' => 'p_aaaa', 'items' => [
            $f('f_first', 'first', 'customer.firstname'), $f('f_pname', 'pname', 'product.name'),
            $f('f_pprice', 'pprice', 'product.price'),
        ]]]];
    }

    private function productRequest(array $fields, int $productId): SubmissionInput
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $k, $d = null) => match ($k) {
                'fields' => $fields,
                'ffb_token' => 'tok123',
                'ffb_product_id' => (string)$productId,
                default => $d,
            }
        );
        return $this->fromHttp($request);
    }

    private function widget(): array
    {
        return ['id' => 9, 'sku' => 'SKU-9', 'name' => 'Widget', 'url' => 'https://shop.test/w.html', 'price' => 19.9,
            'priceFormatted' => '$19.90', 'currency' => 'USD'];
    }

    public function testItOverwritesLockedFieldsWithTheCustomersRealDataBeforeValidation(): void
    {
        $validator = $this->createMock(Validator::class);
        $this->fixture(validator: $validator);
        $this->customer(1, 'a@b.co');
        $this->prefill->method('valuesFor')->willReturn(['customer.firstname' => 'Ann']);
        $this->prefill->method('product')->willReturn($this->widget());
        $validator->expects($this->once())->method('validate')
            ->with($this->anything(), ['first' => 'Ann', 'pname' => 'Widget', 'pprice' => '19.9'])
            ->willReturn(new ValidationResult(['first' => 'Ann'], [], []));

        $result = $this->processor()->process(
            $this->form([], $this->lockedSchema()),
            $this->productRequest(['first' => 'Mallory', 'pname' => 'Evil', 'pprice' => '0.01'], 9)
        );

        $this->assertTrue($result->isSuccess());
    }

    public function testItNeverRequiresALockedFieldWhoseAnswerTheServerOwns(): void
    {
        $validator = $this->createMock(Validator::class);
        $this->fixture(validator: $validator);
        $this->customer(1, 'a@b.co');
        $this->prefill->method('valuesFor')->willReturn(['customer.firstname' => '']);
        $validator->expects($this->once())->method('validate')
            ->with($this->anything(), ['first' => '', 'pname' => '', 'pprice' => ''], $this->anything(), $this->anything(), ['first', 'pname', 'pprice'])
            ->willReturn(new ValidationResult([], [], []));

        $this->processor()->process($this->form([], $this->lockedSchema()), $this->request(['first' => 'Typed']));
    }

    public function testItStoresTheReResolvedProductNameSkuAndPriceWithTheSubmission(): void
    {
        $prefill = $this->createMock(\Furan\FormBuilder\Model\Prefill\PrefillService::class);
        $this->fixture(prefill: $prefill);
        $this->valid(['first' => 'Ann']);
        $prefill->expects($this->once())->method('product')->with(9, 2)->willReturn($this->widget());

        $this->processor()->process($this->form([], $this->lockedSchema()), $this->productRequest([], 9));

        $this->assertSame(
            ['id' => 9, 'sku' => 'SKU-9', 'name' => 'Widget', 'url' => 'https://shop.test/w.html', 'price' => 19.9,
                'currency' => 'USD'],
            $this->submission->getContext()
        );
    }

    public function testItIgnoresAPostedProductNameSkuOrPrice(): void
    {
        $this->valid(['first' => 'Ann']);
        $this->prefill->method('product')->willReturn($this->widget());

        $this->processor()->process(
            $this->form([], $this->lockedSchema()),
            $this->productRequest(['pname' => 'Evil', 'ffb_product_name' => 'Evil', 'ffb_product_price' => '0.01'], 9)
        );

        $context = $this->submission->getContext();
        $this->assertSame('Widget', $context['name']);
        $this->assertSame(19.9, $context['price']);
    }

    public function testItStoresNoProductContextForAnUnknownOrInvisibleProductId(): void
    {
        $this->valid(['first' => 'Ann']);
        $this->prefill->method('product')->willReturn(null);

        $result = $this->processor()->process($this->form([], $this->lockedSchema()), $this->productRequest([], 77));

        $this->assertTrue($result->isSuccess());
        $this->assertNull($this->submission->getContext());
    }

    private function factory(): SubmissionInterfaceFactory
    {
        $factory = $this->createStub(SubmissionInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(function (): SubmissionStub {
            $this->built++;
            return $this->submission;
        });
        return $factory;
    }

    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $store->method('getBaseUrl')->willReturn('https://shop.test/fr/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function savingAs(int $id): void
    {
        $this->repository->method('save')->willReturnCallback(function ($submission) use ($id) {
            $submission->id = $id;
            return $submission;
        });
    }

    public function testItRecordsTheRespondentInTheSameTransactionAsTheSubmission(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->valid(['name' => 'Ann']);
        $this->customer(1, 'c@x.co');
        $this->savingAs(41);
        $respondents->expects($this->once())->method('add')
            ->willReturnCallback(function (int $formId, string $key, int $submissionId): void {
                $this->assertTrue($this->inTransaction);
                $this->assertSame([7, 'c5', 41], [$formId, $key, $submissionId]);
            });

        $this->assertTrue($this->processor()->process($this->form(['survey_mode' => 'once']), $this->request())->isSuccess());
    }

    public function testItRefusesASecondSubmissionFromTheSameCustomer(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $tokens = $this->createMock(TokenService::class);
        $this->fixture(logger: $logger, tokens: $tokens);
        $this->valid(['name' => 'Ann']);
        $this->customer(1, 'c@x.co');
        $this->savingAs(41);
        $this->respondents->method('add')->willThrowException(new AlreadyAnsweredException());
        $logger->expects($this->never())->method('error');
        $tokens->expects($this->once())->method('release')->with('tok123');
        $this->onlyValidateEventsAllowed();

        $result = $this->processor()->process($this->form(['survey_mode' => 'once']), $this->request());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(400, $result->getHttpCode());
        $this->assertSame('You have already answered this form.', $result->getMessage());
    }

    public function testItRemovesTheStoredFilesWhenTheRespondentInsertHitsADuplicate(): void
    {
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(storage: $storage);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $this->customer(1, 'c@x.co');
        $this->savingAs(41);
        $path = 'furan_formbuilder/7/2026/11/' . str_repeat('a', 32);
        $this->storage->method('store')->willReturn(['file' => $path, 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'x']);
        $this->respondents->method('add')->willThrowException(new AlreadyAnsweredException());
        $storage->expects($this->once())->method('delete')->with($path);

        $result = $this->processor()->process($this->form(['survey_mode' => 'once']), $this->request());

        $this->assertSame(400, $result->getHttpCode());
    }

    public function testItAnswersAGuestWhoseEmailAlreadyAnsweredExactlyLikeANewRespondentButStoresNothing(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $tokens = $this->createMock(TokenService::class);
        $this->fixture(respondents: $respondents, tokens: $tokens);
        $this->valid(['name' => 'Ann', 'email' => 'G@x.co']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->savingAs(41);
        $respondents->expects($this->once())->method('assertNotAnswered')->with(7, 'G@x.co');
        $respondents->expects($this->once())->method('add')->with(7, 'eg@x.co', 41)
            ->willThrowException(new AlreadyAnsweredException());
        $this->onlyValidateEventsAllowed();
        $action = $this->assertedAction();
        $action->expects($this->never())->method('execute');
        $tokens->expects($this->never())->method('release');
        $settings = ['survey_mode' => 'once', 'email_field' => 'email', 'guest_dedupe' => true];

        $result = $this->processor([$action])->process($this->form($settings), $this->request());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->getHttpCode());
        $this->assertSame('Thank you, your form has been sent.', $result->getMessage());
    }

    public function testItIgnoresGuestEmailKeysWhenGuestDedupeIsOff(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->valid(['name' => 'Ann', 'email' => 'G@x.co']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->savingAs(41);
        $respondents->expects($this->never())->method('add');

        $result = $this->processor()->process($this->form(['survey_mode' => 'once', 'email_field' => 'email']), $this->request());
        $this->assertTrue($result->isSuccess());
    }

    public function testItSkipsGuestDedupeWithoutAnEmailAnswer(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->valid(['name' => 'Ann', 'email' => '']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->savingAs(41);
        $respondents->expects($this->never())->method('add');

        $result = $this->processor()->process(
            $this->form(['survey_mode' => 'once', 'guest_dedupe' => true, 'email_field' => 'email']),
            $this->request()
        );
        $this->assertTrue($result->isSuccess());
    }

    public function testItAllowsRepeatSubmissionsWhenSurveyModeIsOff(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->valid(['name' => 'Ann', 'email' => 'g@x.co']);
        $this->customer(1, 'c@x.co');
        $this->savingAs(41);
        $respondents->expects($this->never())->method('add');
        $respondents->expects($this->never())->method('assertNotAnswered');

        $this->assertTrue($this->processor()->process($this->form(['guest_dedupe' => true]), $this->request())->isSuccess());
    }

    public function testItKeysALoggedInRespondentByCustomerSoAGuestUsingTheirEmailNeverLocksThemOut(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->valid(['name' => 'Ann', 'email' => 'guest@x.co']);
        $this->customer(1, 'c@x.co');
        $this->savingAs(41);
        $respondents->expects($this->once())->method('add')->with(7, 'c5', 41);

        $this->assertTrue($this->processor()->process(
            $this->form(['survey_mode' => 'once', 'email_field' => 'email']),
            $this->request()
        )->isSuccess());
    }

    public function testItRecordsTheAccountEmailKeyForALoggedInCustomerWithoutLettingItBlockThem(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->valid(['name' => 'Ann', 'email' => 'other@x.co']);
        $this->customer(1, 'C@x.co');
        $this->savingAs(41);
        $respondents->expects($this->never())->method('assertNotAnswered');
        $respondents->expects($this->once())->method('add')->with(7, 'c5', 41);
        $respondents->expects($this->once())->method('addIfAbsent')->with(7, 'ec@x.co', 41);

        $result = $this->processor()->process(
            $this->form(['survey_mode' => 'once', 'guest_dedupe' => true, 'email_field' => 'email']),
            $this->request()
        );
        $this->assertTrue($result->isSuccess(), 'a guest who answered with the account email first cannot lock the customer out');
    }

    public function testItSilentlyDropsAGuestWhoseEmailWasRecordedUnderAnOlderCryptKeyVersion(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->valid(['name' => 'Ann', 'email' => 'g@x.co']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->savingAs(41);
        $this->respondents->method('assertNotAnswered')->willThrowException(new AlreadyAnsweredException());
        $respondents->expects($this->never())->method('add');

        $result = $this->processor()->process(
            $this->form(['survey_mode' => 'once', 'guest_dedupe' => true, 'email_field' => 'email']),
            $this->request()
        );
        $this->assertTrue($result->isSuccess());
    }

    private function editor(array $stored = []): array
    {
        $this->customer(1, 'c@x.co');
        $this->respondents->method('find')->willReturn(['submission_id' => 9]);
        $this->updater->method('storedFiles')->willReturn($stored);

        return ['survey_mode' => 'edit'];
    }

    public function testItUpdatesTheExistingSubmissionInsteadOfCreatingAnother(): void
    {
        $journal = $this->createMock(Journal::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $respondents = $this->createMock(Respondents::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(journal: $journal, repository: $repository, respondents: $respondents, updater: $updater);
        $settings = $this->editor();
        $this->valid(['name' => 'Ann', 'email' => 'a@b.co', 'msg' => '']);
        $repository->expects($this->never())->method('save');
        $journal->expects($this->never())->method('add');
        $respondents->expects($this->never())->method('add');
        $updater->expects($this->once())->method('update')->with(
            $this->anything(),
            9,
            5,
            $this->callback(fn(array $f): bool => count($f) === 4),
            ['name' => 'Ann', 'email' => 'a@b.co', 'msg' => ''],
            $this->callback(fn(array $p): bool => $p[0]['value'] === 'Ann'),
            []
        );

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertTrue($result->isSuccess());
    }

    public function testItRefusesEditsOnceTheSubmissionIsAnsweredOrClosed(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(repository: $repository, storage: $storage);
        $settings = $this->editor();
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3, 'type' => ''];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $path = 'furan_formbuilder/7/2026/11/aa';
        $this->storage->method('store')->willReturn(['file' => $path, 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'x']);
        $this->updater->method('update')->willThrowException(new NotEditableException());
        $storage->expects($this->once())->method('delete')->with($path);
        $repository->expects($this->never())->method('save');

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertSame(400, $result->getHttpCode());
        $this->assertSame('This answer can no longer be changed.', $result->getMessage());
    }

    public function testItExemptsRequiredFieldsThroughTheValidatorPathOnly(): void
    {
        $seen = $this->validateRequiredStoredFile($this->editor(['cv' => ['file' => 'furan_formbuilder/7/2026/11/aa']]));

        $this->assertSame(['cv'], $seen['exempt']);
        $this->assertTrue($seen['schema']['pages'][0]['items'][1]['items'][1]['required']);
        $this->assertTrue($seen['schema']['pages'][0]['items'][0]['required']);
        $this->assertTrue($seen['result']->isSuccess());
    }

    public function testItKeepsTheStoredFileOfAnEditLinkWhenARequiredFileFieldIsLeftEmpty(): void
    {
        $this->customer(1, 'c@x.co');
        $this->editParam = 'edit.token';
        $this->editToken->method('verify')->willReturn(9);
        $this->updater->method('storedFiles')->willReturn(['cv' => ['file' => 'furan_formbuilder/7/2026/11/aa']]);

        $seen = $this->validateRequiredStoredFile(['account_edit' => true]);

        $this->assertSame(['cv'], $seen['exempt']);
        $this->assertTrue($seen['schema']['pages'][0]['items'][1]['items'][1]['required']);
        $this->assertTrue($seen['result']->isSuccess());
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{schema: ?array, exempt: ?array, result: \Furan\FormBuilder\Model\Submission\Result}
     */
    private function validateRequiredStoredFile(array $settings): array
    {
        $schema = $this->schema();
        $schema['pages'][0]['items'][1]['items'][1]['required'] = true;
        $schema['pages'][0]['items'][0]['required'] = true;
        $seen = ['schema' => null, 'exempt' => null];
        $this->validator->method('validate')->willReturnCallback(
            function (array $given, array $fields, array $files, int $store, array $exempt) use (&$seen): ValidationResult {
                $seen = ['schema' => $given, 'exempt' => $exempt];
                return new ValidationResult(['name' => 'Ann'], [], []);
            }
        );
        $seen['result'] = $this->processor()->process($this->form($settings, $schema), $this->request());

        return $seen;
    }

    public function testItRunsOnlyActionsThatSupportEditsWhenASubmissionIsEdited(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $settings = $this->editor();
        $this->valid(['name' => 'Ann']);
        $stored = new SubmissionStub();
        $stored->id = 9;
        $repository->method('getById')->with(9)->willReturn($stored);
        $mail = $this->assertedAction(SubmissionActionInterface::class, false);
        $mail->expects($this->never())->method('execute');
        $sync = $this->assertedAction();
        $sync->expects($this->once())->method('execute')->with(
            $this->anything(),
            $stored,
            ['name' => 'Ann'],
            $this->callback(static fn (\Furan\FormBuilder\Api\ActionContext $c): bool => $c->isEdit)
        );
        $this->onlyValidateEventsAllowed();

        $this->assertTrue($this->processor([$mail, $sync])->process($this->form($settings), $this->request())->isSuccess());
    }

    public function testItRefusesASurveyEditWhenTheCustomerDeletedTheirAnswer(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $validator = $this->createMock(Validator::class);
        $this->fixture(repository: $repository, updater: $updater, validator: $validator);
        $this->customer(1, 'c@x.co');
        $this->respondents->method('find')->willReturn(['submission_id' => null]);
        $validator->expects($this->never())->method('validate');
        $repository->expects($this->never())->method('save');
        $updater->expects($this->never())->method('update');

        $result = $this->processor()->process($this->form(['survey_mode' => 'edit']), $this->request());

        $this->assertSame(400, $result->getHttpCode());
        $this->assertSame('You have already answered this form.', $result->getMessage());
    }

    public function testItInsertsTheFirstAnswerOfASurveyEditForm(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(repository: $repository, updater: $updater);
        $this->customer(1, 'c@x.co');
        $this->respondents->method('find')->willReturn(null);
        $this->valid(['name' => 'Ann']);
        $updater->expects($this->never())->method('update');
        $repository->expects($this->once())->method('save');

        $this->assertTrue($this->processor()->process($this->form(['survey_mode' => 'edit']), $this->request())->isSuccess());
    }

    private function editLink(array $settings = ['account_edit' => true], int $submissionId = 9): array
    {
        $this->customer(1, 'c@x.co');
        $this->editParam = 'edit.token';
        $this->editToken->method('verify')->with('edit.token', 5, 7)->willReturn($submissionId);
        $this->updater->method('storedFiles')->willReturn([]);

        return $settings;
    }

    private function impersonated(): void
    {
        $this->session->method('getData')->with('logged_as_customer_admind_id')->willReturn(3);
    }

    private function assertImpersonationRefusal(\Furan\FormBuilder\Model\Submission\Result $result): void
    {
        $this->assertFalse($result->isSuccess());
        $this->assertSame(403, $result->getHttpCode());
        $this->assertSame(
            'Survey answers cannot be submitted or changed while you are logged in as this customer.',
            $result->getMessage()
        );
    }

    public function testItRefusesASurveySubmissionWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $session = $this->createMock(Session::class);
        $tokens = $this->createMock(TokenService::class);
        $this->fixture(session: $session, tokens: $tokens);
        $this->impersonated();
        $this->customer(1, 'c@x.co');
        $this->valid(['name' => 'Ann']);
        $tokens->expects($this->once())->method('release')->with('tok123');

        $this->assertImpersonationRefusal(
            $this->processor()->process($this->form(['survey_mode' => 'once']), $this->request())
        );
    }

    public function testItRefusesASurveyEditWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $session = $this->createMock(Session::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(session: $session, updater: $updater);
        $this->impersonated();
        $settings = $this->editor();
        $updater->expects($this->never())->method('update');

        $this->assertImpersonationRefusal($this->processor()->process($this->form($settings), $this->request()));
    }

    public function testItRefusesAMySubmissionsEditTokenSubmitWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $session = $this->createMock(Session::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(editToken: $editToken, session: $session, updater: $updater);
        $this->impersonated();
        $settings = $this->editLink();
        $updater->expects($this->never())->method('update');

        $this->assertImpersonationRefusal($this->processor()->process($this->form($settings), $this->request()));
    }

    public function testItStillAcceptsASubmissionToAFormWithoutSurveyModeWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $session = $this->createMock(Session::class);
        $this->fixture(repository: $repository, session: $session);
        $this->impersonated();
        $this->customer(1, 'c@x.co');
        $this->valid(['name' => 'Ann']);
        $repository->expects($this->once())->method('save');

        $this->assertTrue($this->processor()->process($this->form(), $this->request())->isSuccess());
    }

    public function testItStoresNoFilesAndNoRespondentRowForARefusedImpersonatedSubmit(): void
    {
        $journal = $this->createMock(Journal::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $respondents = $this->createMock(Respondents::class);
        $session = $this->createMock(Session::class);
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(journal: $journal, repository: $repository, respondents: $respondents, session: $session, storage: $storage);
        $this->impersonated();
        $this->customer(1, 'c@x.co');
        $this->valid(['name' => 'Ann'], ['cv' => ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3]]);
        $storage->expects($this->never())->method('store');
        $respondents->expects($this->never())->method('add');
        $journal->expects($this->never())->method('add');
        $repository->expects($this->never())->method('save');

        $this->assertImpersonationRefusal(
            $this->processor()->process($this->form(['survey_mode' => 'once']), $this->request())
        );
    }

    public function testItUpdatesTheSubmissionNamedByAValidEditToken(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $journal = $this->createMock(Journal::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $respondents = $this->createMock(Respondents::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(editToken: $editToken, journal: $journal, repository: $repository, respondents: $respondents, updater: $updater);
        $settings = $this->editLink();
        $this->valid(['name' => 'Ann']);
        $respondents->expects($this->never())->method('find');
        $repository->expects($this->never())->method('save');
        $journal->expects($this->never())->method('add');
        $this->onlyValidateEventsAllowed();
        $updater->expects($this->once())->method('update')
            ->with($this->anything(), 9, 5, $this->anything(), ['name' => 'Ann'], $this->anything(), []);

        $this->assertTrue($this->processor()->process($this->form($settings), $this->request())->isSuccess());
    }

    public function testItRefusesAnEditTokenWhenTheFormDoesNotAllowAccountEdits(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $validator = $this->createMock(Validator::class);
        $this->fixture(editToken: $editToken, repository: $repository, updater: $updater, validator: $validator);
        $settings = $this->editLink(['account_edit' => false]);
        $this->assertExpiredEditLink($settings);
    }

    public function testItRefusesAnInvalidEditTokenOnSubmitInsteadOfCreatingANewSubmission(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $validator = $this->createMock(Validator::class);
        $this->fixture(repository: $repository, updater: $updater, validator: $validator);
        $this->customer(1, 'c@x.co');
        $this->editParam = 'edit.token';
        $this->editToken->method('verify')->willReturn(null);

        $this->assertExpiredEditLink(['account_edit' => true]);
    }

    public function testItRefusesAnEditTokenFromAGuestSession(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $validator = $this->createMock(Validator::class);
        $this->fixture(editToken: $editToken, repository: $repository, updater: $updater, validator: $validator);
        $this->editParam = 'edit.token';
        $editToken->expects($this->never())->method('verify');

        $this->assertExpiredEditLink(['account_edit' => true]);
    }

    public function testItRefusesAnEditTokenWhenTheTargetCanNoLongerBeEdited(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(editToken: $editToken, repository: $repository);
        $settings = $this->editLink();
        $this->valid(['name' => 'Ann']);
        $this->updater->method('update')->willThrowException(new NotEditableException());
        $repository->expects($this->never())->method('save');

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertSame(400, $result->getHttpCode());
        $this->assertSame(
            'This edit link has expired. Open the submission from your account again.',
            $result->getMessage()
        );
    }

    public function testItDoesNotBuildANewSubmissionForAnEdit(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $updater = $this->createMock(SubmissionUpdater::class);
        $this->fixture(editToken: $editToken, updater: $updater);
        $settings = $this->editLink();
        $this->valid(['name' => 'Ann']);
        $updater->expects($this->once())->method('update');

        $this->assertTrue($this->processor()->process($this->form($settings), $this->request())->isSuccess());
        $this->assertSame(0, $this->built);
    }

    private function assertExpiredEditLink(array $settings): void
    {
        $this->validator->expects($this->never())->method('validate');
        $this->repository->expects($this->never())->method('save');
        $this->updater->expects($this->never())->method('update');

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(400, $result->getHttpCode());
        $this->assertSame(
            'This edit link has expired. Open the submission from your account again.',
            $result->getMessage()
        );
    }

    public function testItAppliesThePerFormCeilingOnlyAfterTheTokenIsSpent(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $tokens = $this->createMock(TokenService::class);
        $this->fixture(repository: $repository, tokens: $tokens);
        $this->valid(['name' => 'Ann']);
        $this->ceilingHit = true;
        $repository->expects($this->never())->method('save');
        $tokens->expects($this->once())->method('release')->with('tok123');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertSame(429, $result->getHttpCode());
        $this->assertSame('Too many submissions. Please wait a few minutes and try again.', $result->getMessage());
    }

    public function testItSkipsThePerFormCeilingForRejectedTokens(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $this->fixture(rateLimiter: $rateLimiter);
        $this->requestSpam = SpamResult::reject('token');
        $rateLimiter->expects($this->never())->method('formCeilingReached');

        $this->assertSame(400, $this->processor()->process($this->form(), $this->request())->getHttpCode());
    }

    public function testItRefusesUploadsOnceTheFormsHourlyByteBudgetIsSpent(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $storage = $this->createMock(FileStorage::class);
        $this->fixture(repository: $repository, storage: $storage);
        $upload = ['name' => 'cv.pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $this->valid(['name' => 'Ann'], ['cv' => $upload]);
        $this->budgetLeft = false;
        $storage->expects($this->never())->method('store');
        $repository->expects($this->never())->method('save');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertSame(422, $result->getHttpCode());
        $this->assertSame(
            ['cv' => 'This form is receiving too many files right now. Please try again later.'],
            $result->getErrors()
        );
    }
    /** @return array{schema: ?array, posted: ?array, result: \Furan\FormBuilder\Model\Submission\Result} what the validator received */
    private function submitNewsletter(bool $required, array $posted, string $type = 'newsletter'): array
    {
        $bridge = $this->createStub(\Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn(true);
        $this->extraTypes = [
            'newsletter' => new \Furan\FormBuilder\Model\FieldType\Newsletter($bridge),
            \Furan\FormBuilder\Test\Unit\Helper\SmsConsentType::CODE => new \Furan\FormBuilder\Test\Unit\Helper\SmsConsentType(),
        ];
        $schema = ['pages' => [['id' => 'p_aaaa', 'items' => [
            ['id' => 'f_name1', 'kind' => 'field', 'type' => 'text', 'name' => 'name', 'label' => 'Name'],
            ['id' => 'f_news1', 'kind' => 'field', 'type' => $type, 'name' => 'news', 'label' => 'News', 'required' => $required],
        ]]]];
        $seen = ['schema' => null, 'posted' => null, 'exempt' => null];
        $this->validator->method('validate')->willReturnCallback(
            function (array $schema, array $fields, array $files, int $store, array $exempt) use (&$seen): ValidationResult {
                $seen = ['schema' => $schema, 'posted' => $fields, 'exempt' => $exempt];
                return new ValidationResult(['name' => 'Ann'], [], []);
            }
        );
        $this->customer(1, 'c@x.co');
        $seen['result'] = $this->processor()->process($this->form([], $schema), $this->request($posted));

        return $seen;
    }

    public function testItForcesTheNewsletterAnswerUntickedUnderLoginAsCustomer(): void
    {
        $session = $this->createMock(Session::class);
        $this->fixture(session: $session);
        $this->impersonated();

        $seen = $this->submitNewsletter(false, ['name' => 'Ann', 'news' => 'yes']);

        $this->assertSame('', $seen['posted']['news']);
        $this->assertSame('Ann', $seen['posted']['name']);
        $this->assertTrue($seen['result']->isSuccess());
    }

    public function testItTreatsAnyConsentTypeAsNeverRequiredBlankedUnderLoginAsCustomerNotDraftableAndRefusedHideAndRequireActionsOnSubmit(): void
    {
        $session = $this->createMock(Session::class);
        $this->fixture(session: $session);
        $this->impersonated();

        $seen = $this->submitNewsletter(true, ['name' => 'Ann', 'news' => 'yes'], \Furan\FormBuilder\Test\Unit\Helper\SmsConsentType::CODE);

        $this->assertSame('', $seen['posted']['news']);
        $this->assertSame(['news'], $seen['exempt']);
    }

    public function testItKeepsTheNewsletterAnswerForAVisitorWhoIsNotImpersonated(): void
    {
        $seen = $this->submitNewsletter(false, ['name' => 'Ann', 'news' => 'yes']);

        $this->assertSame('yes', $seen['posted']['news']);
    }

    public function testItAcceptsASubmitWithARequiredNewsletterFieldUnderLoginAsCustomer(): void
    {
        $session = $this->createMock(Session::class);
        $this->fixture(session: $session);
        $this->impersonated();

        $seen = $this->submitNewsletter(true, ['name' => 'Ann']);

        $this->assertTrue($seen['schema']['pages'][0]['items'][1]['required']);
        $this->assertSame(['news'], $seen['exempt']);
        $this->assertTrue($seen['result']->isSuccess());
    }

    public function testItKeepsAForcedUntickedNewsletterFieldOptionalUnderLoginAsCustomer(): void
    {
        $session = $this->createMock(Session::class);
        $this->fixture(session: $session);
        $this->impersonated();

        $seen = $this->submitNewsletter(false, ['name' => 'Ann']);

        $this->assertSame(['news'], $seen['exempt']);
    }

    public function testItKeepsAStoredFileFieldOptionalOnEditEvenWhenARequireActionMatches(): void
    {
        $validator = $this->createMock(Validator::class);
        $this->fixture(validator: $validator);
        $settings = $this->editor(['cv' => ['file' => 'furan_formbuilder/7/2026/11/aa']]);
        $validator->expects($this->once())->method('validate')->with(
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->callback(static fn (array $exempt): bool => in_array('cv', $exempt, true))
        )->willReturn(new ValidationResult(['name' => 'Ann'], [], []));

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertTrue($result->isSuccess());
    }

    private const REFERENCE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function testItReturnsTheReferenceInTheSubmitResponse(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->savingAs(41);

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertMatchesRegularExpression(self::REFERENCE_PATTERN, (string)$result->getReference());
        $this->assertSame($this->submission->getReference(), $result->getReference());
    }

    public function testItReturnsAReferenceForAFormThatDoesNotStoreSubmissions(): void
    {
        $this->valid(['name' => 'Ann']);

        $result = $this->processor()->process($this->form(['store_submissions' => false]), $this->request());

        $this->assertSame($this->submission->getReference(), $result->getReference());
        $this->assertNotSame('', (string)$result->getReference());
    }

    public function testItReplacesTheReferencePlaceholderInTheSuccessMessage(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->savingAs(41);

        $result = $this->processor()->process(
            $this->form(['success_message' => 'Ref {{reference}} / {{reference}} saved.']),
            $this->request()
        );

        $ref = (string)$result->getReference();
        $this->assertSame('Ref ' . $ref . ' / ' . $ref . ' saved.', $result->getMessage());
    }

    public function testItReturnsAReferenceOnASilentSpamSuccessAndOnADuplicateGuestAnswer(): void
    {
        $this->requestSpam = SpamResult::silent('honeypot');
        $silent = $this->processor()->process(
            $this->form(['success_message' => 'Ref {{reference}}']),
            $this->request()
        );
        $this->assertMatchesRegularExpression(self::REFERENCE_PATTERN, (string)$silent->getReference());
        $this->assertSame('Ref ' . $silent->getReference(), $silent->getMessage());
    }

    public function testItReturnsAFreshReferenceForAValuesStageSilentResult(): void
    {
        $this->valid(['name' => 'Ann']);
        $this->valuesSpam = SpamResult::silent('bot');

        $result = $this->processor()->process($this->form(), $this->request());

        $this->assertMatchesRegularExpression(self::REFERENCE_PATTERN, (string)$result->getReference());
    }

    public function testItReturnsAFreshUnstoredReferenceForADuplicateGuestAnswer(): void
    {
        $this->valid(['name' => 'Ann', 'email' => 'G@x.co']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->savingAs(41);
        $this->respondents->method('add')->willThrowException(new AlreadyAnsweredException());
        $settings = ['survey_mode' => 'once', 'email_field' => 'email', 'guest_dedupe' => true];

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertTrue($result->isSuccess());
        $this->assertMatchesRegularExpression(self::REFERENCE_PATTERN, (string)$result->getReference());
        $this->assertNotSame($this->submission->getReference(), '');
    }

    public function testItReturnsTheStoredReferenceAfterAnEdit(): void
    {
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $settings = $this->editor() + ['success_message' => 'Ref {{reference}}'];
        $this->valid(['name' => 'Ann']);
        $stored = new SubmissionStub();
        $stored->id = 9;
        $stored->setReference('0194f3a0-1111-7222-8333-444455556666');
        $repository->method('getById')->with(9)->willReturn($stored);

        $result = $this->processor()->process($this->form($settings), $this->request());

        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $result->getReference());
        $this->assertSame('Ref 0194f3a0-1111-7222-8333-444455556666', $result->getMessage());
    }

    public function testItWritesDeliveriesInsideTheSubmitTransactionAndNoneWhenItRollsBack(): void
    {
        $deliveries = $this->createMock(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->fixture(deliveries: $deliveries);
        $this->valid(['name' => 'Ann']);
        $this->savingAs(41);
        $deliveries->expects($this->once())->method('record')
            ->willReturnCallback(function (int $id, int $formId, ?int $storeId, string $event): array {
                $this->assertTrue($this->inTransaction);
                $this->assertSame([41, 7, 2, 'submission.created'], [$id, $formId, $storeId, $event]);
                return [90];
            });
        $deliveries->expects($this->once())->method('wake')->with([90]);

        $this->assertTrue($this->processor()->process($this->form(), $this->request())->isSuccess());
    }

    public function testItLeavesNoDeliveryWhenTheSubmitTransactionRollsBack(): void
    {
        $deliveries = $this->createMock(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->fixture(deliveries: $deliveries);
        $this->valid(['name' => 'Ann', 'email' => 'G@x.co']);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->savingAs(41);
        $this->respondents->method('add')->willThrowException(new AlreadyAnsweredException());
        $deliveries->expects($this->never())->method('record');
        $deliveries->expects($this->never())->method('wake');

        $this->processor()->process(
            $this->form(['survey_mode' => 'once', 'email_field' => 'email', 'guest_dedupe' => true]),
            $this->request()
        );
    }

    public function testItWakesTheWorkerOnlyAfterCommit(): void
    {
        $deliveries = $this->createMock(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->fixture(deliveries: $deliveries);
        $this->valid(['name' => 'Ann']);
        $this->savingAs(41);
        $this->deliveries->method('record')->willReturn([90, 91]);
        $deliveries->expects($this->once())->method('wake')->with([90, 91])
            ->willReturnCallback(function (): void {
                $this->assertFalse($this->inTransaction, 'woken before commit');
            });

        $this->processor()->process($this->form(), $this->request());
    }

    public function testItEnqueuesNothingForFormsThatDoNotStoreSubmissions(): void
    {
        $deliveries = $this->createMock(\Furan\FormBuilder\Model\Webhook\SubmissionDeliveries::class);
        $this->fixture(deliveries: $deliveries);
        $this->valid(['name' => 'Ann']);
        $deliveries->expects($this->never())->method('record');
        $deliveries->expects($this->never())->method('wake');

        $this->assertTrue(
            $this->processor()->process($this->form(['store_submissions' => false]), $this->request())->isSuccess()
        );
    }
}
