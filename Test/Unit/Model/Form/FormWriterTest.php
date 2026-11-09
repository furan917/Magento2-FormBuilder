<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\Form\FormWriter;
use Furan\FormBuilder\Model\Form\ScheduleTimezone;
use Furan\FormBuilder\Api\FormWriteContext;
use Furan\FormBuilder\Model\FormFactory;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Schema\SettingsNormalizer;
use Furan\FormBuilder\Model\Schema\UnsupportedSchemaVersionException;
use Furan\FormBuilder\Model\Settings\ContributorPool;
use Furan\FormBuilder\Model\Settings\ProtectedSettings;
use Furan\FormBuilder\Test\Unit\Helper\ArraySettingsContributor;
use Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\TranslatorFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormWriterTest extends TestCase
{
    use AssertedDoubles;

    private const PERSONAL = 'Furan_FormBuilder::webhooks_personal';

    private FormRepositoryInterface&Stub $repository;
    private FormFactory&Stub $factory;
    private FormResource&Stub $resource;
    private Normalizer&Stub $normalizer;
    private SettingsNormalizer&Stub $settingsNormalizer;
    private SchemaMigrator&Stub $migrator;
    private ScheduleTimezone&Stub $scheduleTimezone;
    private LoggerInterface&Stub $logger;
    private ContributorPool $contributors;
    /** @var list<int> */
    private array $storeIds = [2, 3];

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FormRepositoryInterface::class);
        $this->repository->method('save')->willReturnArgument(0);
        $this->factory = $this->createStub(FormFactory::class);
        $this->resource = $this->createStub(FormResource::class);
        $this->normalizer = $this->createStub(Normalizer::class);
        $this->normalizer->method('normalize')->willReturn(['clean' => 'schema']);
        $this->settingsNormalizer = $this->createStub(SettingsNormalizer::class);
        $this->settingsNormalizer->method('pruneRouting')->willReturnArgument(0);
        $this->settingsNormalizer->method('normalize')->willReturn(['clean' => 'settings']);
        $this->migrator = $this->createStub(SchemaMigrator::class);
        $this->migrator->method('upgrade')->willReturnArgument(0);
        $this->migrator->method('isSupported')->willReturn(true);
        $this->scheduleTimezone = $this->createStub(ScheduleTimezone::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->contributors = ArraySettingsContributor::pool();
    }

    private function writer(): FormWriter
    {
        $stores = [];
        foreach ($this->storeIds as $id) {
            $stores[$id] = new \stdClass();
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([0 => new \stdClass()] + $stores);

        return new FormWriter(
            $this->repository,
            $this->factory,
            $this->resource,
            $this->normalizer,
            $this->settingsNormalizer,
            new ProtectedSettings($this->contributors),
            $this->migrator,
            TranslatorFactory::create($this->createStub(\Furan\FormBuilder\Model\Url\RedirectValidator::class)),
            $this->scheduleTimezone,
            new \Furan\FormBuilder\Model\Form\PersonalUnticks(new PersonalData(FieldPools::builtIn())),
            $manager,
            $this->logger
        );
    }

    private function definition(array $override = []): array
    {
        return $override + [
            'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true, 'store_ids' => [0],
            'schema' => ['version' => 1, 'pages' => []], 'settings' => ['submit_label' => 'Go'],
        ];
    }

    /** @template T of Form&Stub @param T $form @return T */
    private function chainable(Form&Stub $form): Form&Stub
    {
        foreach (['setIdentifier', 'setTitle', 'setIsActive', 'setSchema', 'setSettings', 'setStoreIds', 'setTranslations'] as $m) {
            $form->method($m)->willReturnSelf();
        }
        return $form;
    }

    private function newForm(): Form&Stub
    {
        $form = $this->chainable($this->createStub(Form::class));
        $this->factory->method('create')->willReturn($form);
        return $form;
    }

    private function untouchedForm(array $schema = ['version' => 1], array $settings = []): Form&MockObject
    {
        $form = $this->createMock(Form::class);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getSettings')->willReturn($settings);
        foreach (['setIdentifier', 'setTitle', 'setIsActive', 'setSchema', 'setSettings', 'setStoreIds', 'setTranslations'] as $m) {
            $form->expects($this->never())->method($m);
        }
        return $form;
    }

    private function allow(string ...$denied): FormWriteContext
    {
        return new FormWriteContext(static fn (string $r): bool => !in_array($r, $denied, true), 7);
    }

    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    public function testItSavesAValidDefinitionThroughTheNormalizers(): void
    {
        $this->normalizer = $this->createMock(Normalizer::class);
        $this->normalizer->expects($this->once())->method('normalize')->with(['version' => 1, 'pages' => []], null)
            ->willReturn(['clean' => 'schema']);
        $this->settingsNormalizer = $this->createMock(SettingsNormalizer::class);
        $this->settingsNormalizer->expects($this->once())->method('normalize')
            ->with(['submit_label' => 'Go'], ['clean' => 'schema'], [], 'Asia/Tokyo')->willReturn(['clean' => 'settings']);
        $this->scheduleTimezone = $this->createMock(ScheduleTimezone::class);
        $this->scheduleTimezone->expects($this->once())->method('defaultFor')->with([0, 2])->willReturn('Asia/Tokyo');
        $form = $this->createMock(Form::class);
        $form->expects($this->once())->method('setIdentifier')->with('contact')->willReturnSelf();
        $form->expects($this->once())->method('setTitle')->with('Contact')->willReturnSelf();
        $form->expects($this->once())->method('setIsActive')->with(true)->willReturnSelf();
        $form->expects($this->once())->method('setStoreIds')->with([0, 2])->willReturnSelf();
        $form->expects($this->once())->method('setSchema')->with(['clean' => 'schema'])->willReturnSelf();
        $form->expects($this->once())->method('setSettings')->with(['clean' => 'settings'])->willReturnSelf();
        $form->expects($this->never())->method('setTranslations');
        $this->factory->method('create')->willReturn($form);

        $this->assertSame($form, $this->writer()->save(null, $this->definition(['store_ids' => [0, '2', 2]]), $this->allow()));
    }

    public function testItUpdatesTheGivenFormInsteadOfCreatingOne(): void
    {
        $this->factory = $this->createMock(FormFactory::class);
        $this->factory->expects($this->never())->method('create');
        $existing = $this->chainable($this->createStub(Form::class));
        $existing->method('getId')->willReturn(5);
        $this->resource->method('getIdByIdentifier')->willReturn(5);

        $this->assertSame($existing, $this->writer()->save($existing, $this->definition(), $this->allow()));
    }

    public function testItEmptiesAnEmptyStoreListToAllStores(): void
    {
        $form = $this->createMock(Form::class);
        $form->expects($this->once())->method('setStoreIds')->with([0])->willReturnSelf();
        foreach (['setIdentifier', 'setTitle', 'setIsActive', 'setSchema', 'setSettings'] as $m) {
            $form->method($m)->willReturnSelf();
        }
        $this->factory->method('create')->willReturn($form);

        $this->writer()->save(null, $this->definition(['store_ids' => []]), $this->allow());
    }

    /** @return array<string, array{mixed}> */
    public static function badStoreIds(): array
    {
        return [
            'negative string' => ['-1'], 'negative int' => [-1], 'float' => [1.5], 'exponent' => ['1e3'],
            'text' => ['abc'], 'empty' => [''], 'list' => [[1]],
        ];
    }

    #[DataProvider('badStoreIds')]
    public function testItRejectsStoreIdsThatAreNotNonNegativeIntegersOnImportAndSave(mixed $bad): void
    {
        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->expects($this->never())->method('save');
        $this->newForm();
        $label = is_scalar($bad) ? (string)$bad : (string)json_encode($bad);

        $errors = $this->errorsOf(fn () => $this->writer()->save(null, $this->definition(['store_ids' => [0, $bad]]), $this->allow()));

        $this->assertSame(['Store view ' . $label . ' does not exist.'], $errors);
    }

    public function testItRejectsAStoreIdThatDoesNotExist(): void
    {
        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->expects($this->never())->method('save');
        $this->newForm();

        $errors = $this->errorsOf(fn () => $this->writer()->save(null, $this->definition(['store_ids' => [2, 9, '8']]), $this->allow()));

        $this->assertSame(['Store view 9 does not exist.', 'Store view 8 does not exist.'], $errors);
    }

    public function testItTrimsTheTitleBeforeStoringOnImportAndSave(): void
    {
        $form = $this->createMock(Form::class);
        $form->expects($this->once())->method('setTitle')->with('x')->willReturnSelf();
        foreach (['setIdentifier', 'setIsActive', 'setSchema', 'setSettings', 'setStoreIds'] as $m) {
            $form->method($m)->willReturnSelf();
        }
        $this->factory->method('create')->willReturn($form);

        $this->writer()->save(null, $this->definition(['title' => "  x \n"]), $this->allow());
    }

    public function testItReportsEveryInvalidIdentifierTitleAndSchemaMessage(): void
    {
        $this->normalizer = $this->createStub(Normalizer::class);
        $this->normalizer->method('normalize')->willThrowException(new InvalidSchemaException(['bad schema']));
        $this->newForm();

        $errors = $this->errorsOf(fn () => $this->writer()->save(null, $this->definition(['identifier' => 'Bad Id', 'title' => ' ']), $this->allow()));

        $this->assertSame([
            'The identifier may only contain lowercase letters, numbers and hyphens (max 64).',
            'The title must be between 1 and 255 characters.',
            'bad schema',
        ], $errors);
    }

    public function testItRejectsAnIdentifierUsedByAnotherForm(): void
    {
        $this->newForm();
        $this->resource->method('getIdByIdentifier')->willReturn(4);

        $errors = $this->errorsOf(fn () => $this->writer()->save(null, $this->definition(), $this->allow()));

        $this->assertSame(['The identifier "contact" is already used.'], $errors);
    }

    public function testItAcceptsTheIdentifierOfTheFormBeingUpdated(): void
    {
        $existing = $this->chainable($this->createStub(Form::class));
        $existing->method('getId')->willReturn('5');
        $this->resource->method('getIdByIdentifier')->willReturn(5);

        $this->assertSame($existing, $this->writer()->save($existing, $this->definition(), $this->allow()));
    }

    public function testItRejectsSchemaAndSettingsThatAreNotObjects(): void
    {
        $this->newForm();

        $errors = $this->errorsOf(fn () => $this->writer()->save(null, $this->definition(['settings' => 'x']), $this->allow()));

        $this->assertSame(['The form data could not be read.'], $errors);
    }

    public function testItMigratesAnOlderSchemaVersionOnAdminSaveAsWellAsImport(): void
    {
        $this->migrator = $this->createMock(SchemaMigrator::class);
        $this->migrator->method('isSupported')->willReturn(true);
        $this->migrator->expects($this->once())->method('upgrade')->with(['raw' => 1])->willReturn(['migrated' => 1]);
        $this->normalizer = $this->createMock(Normalizer::class);
        $this->normalizer->expects($this->once())->method('normalize')->with(['migrated' => 1])->willReturn(['clean' => 1]);
        $this->newForm();

        $this->writer()->save(null, $this->definition(['schema' => ['raw' => 1]]), $this->allow());
    }

    public function testItReportsASubmittedSchemaNewerThanTheModuleSupports(): void
    {
        $this->migrator = $this->createStub(SchemaMigrator::class);
        $this->migrator->method('isSupported')->willReturn(true);
        $this->migrator->method('upgrade')->willThrowException(new UnsupportedSchemaVersionException(9, 'Schema 9 is newer.'));
        $this->newForm();

        $errors = $this->errorsOf(fn () => $this->writer()->save(null, $this->definition(), $this->allow()));

        $this->assertSame(['Schema 9 is newer.'], $errors);
    }

    public function testItRefusesToOverwriteAFormSavedByANewerSchemaVersionOnEveryPath(): void
    {
        $this->migrator = $this->createStub(SchemaMigrator::class);
        $this->migrator->method('isSupported')->willReturn(false);
        $this->migrator->method('versionOf')->willReturn(9);
        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->expects($this->never())->method('save');
        $this->normalizer = $this->createMock(Normalizer::class);
        $this->normalizer->expects($this->never())->method('normalize');
        $stored = $this->untouchedForm(['version' => 9]);

        $asExisting = fn () => $this->writer()->save($stored, $this->definition(), $this->allow());
        $asBaseline = fn () => $this->writer()->save(null, $this->definition(), $this->allow(), $stored);
        foreach ([$asExisting, $asBaseline] as $path) {
            try {
                $path();
                $this->fail('Expected UnsupportedSchemaVersionException');
            } catch (UnsupportedSchemaVersionException $e) {
                $this->assertSame(
                    ['This form was saved by a newer version of the module (schema version 9) and cannot be changed here.'],
                    $e->getErrors()
                );
                $this->assertSame(9, $e->getVersion());
            }
        }
    }

    public function testItKeepsProtectedSettingsFromTheStoredFormWhenTheContextDeniesTheResource(): void
    {
        $this->contributors = ArraySettingsContributor::pool(
            new ArraySettingsContributor('acme_crm', null, 'Acme_Crm::settings'),
            new ArraySettingsContributor('open_ext', null, null)
        );
        $stored = [
            'notify_enabled' => true, 'notify_recipients' => ['ops@x.test'], 'notify_subject' => 'Stored',
            'ext' => ['acme_crm' => ['list_id' => 'kept'], 'open_ext' => ['list_id' => 'old']],
        ];
        $existing = $this->chainable($this->createStub(Form::class));
        $existing->method('getSettings')->willReturn($stored);
        $this->settingsNormalizer = $this->createMock(SettingsNormalizer::class);
        $this->settingsNormalizer->method('pruneRouting')->willReturnArgument(0);
        $this->settingsNormalizer->expects($this->once())->method('normalize')->with(
            $this->callback(static fn (array $s): bool => $s['submit_label'] === 'Go'
                && $s['notify_enabled'] === true
                && $s['notify_recipients'] === ['ops@x.test']
                && $s['notify_subject'] === 'Stored'
                && !array_key_exists('store_ip', $s)
                && $s['ext']['acme_crm'] === ['list_id' => 'kept']
                && $s['ext']['open_ext'] === ['list_id' => 'new']),
            ['clean' => 'schema'],
            $stored
        )->willReturn([]);
        $this->resource->method('getIdByIdentifier')->willReturn(null);
        $settings = ['submit_label' => 'Go', 'notify_enabled' => false, 'notify_recipients' => ['evil@x.test'],
            'notify_subject' => 'Hi', 'store_ip' => true,
            'ext' => ['acme_crm' => ['list_id' => 'evil'], 'open_ext' => ['list_id' => 'new']]];

        $this->writer()->save($existing, $this->definition(['settings' => $settings]), $this->allow('Furan_FormBuilder::submissions', 'Acme_Crm::settings'));
    }

    public function testItPrunesRoutingOnlyWhenTheContextDeniesSubmissions(): void
    {
        foreach ([[true, 0], [false, 1]] as [$allowed, $prunes]) {
            $this->settingsNormalizer = $this->createMock(SettingsNormalizer::class);
            $this->settingsNormalizer->expects($this->exactly($prunes))->method('pruneRouting')
                ->with($this->anything(), ['clean' => 'schema'])->willReturnArgument(0);
            $this->settingsNormalizer->method('normalize')->willReturn([]);
            $this->factory = $this->createStub(FormFactory::class);
            $this->newForm();

            $this->writer()->save(null, $this->definition(), $this->allow(...($allowed ? [] : ['Furan_FormBuilder::submissions'])));
        }
    }

    public function testItKeepsFieldsOfUnavailableTypesAndProtectedSettingsWhenDuplicating(): void
    {
        $storedSchema = ['version' => 1, 'pages' => [['id' => 'p_1']]];
        $storedSettings = ['notify_recipients' => ['ops@x.test']];
        $source = $this->createStub(Form::class);
        $source->method('getSchema')->willReturn($storedSchema);
        $source->method('getSettings')->willReturn($storedSettings);
        $this->normalizer = $this->createMock(Normalizer::class);
        $this->normalizer->expects($this->once())->method('normalize')->with($storedSchema, $storedSchema)->willReturn(['clean' => 'schema']);
        $this->settingsNormalizer = $this->createMock(SettingsNormalizer::class);
        $this->settingsNormalizer->method('pruneRouting')->willReturnArgument(0);
        $this->settingsNormalizer->expects($this->once())->method('normalize')->with(
            $this->callback(static fn (array $s): bool => $s['notify_recipients'] === ['ops@x.test']),
            ['clean' => 'schema'],
            $storedSettings
        )->willReturn([]);
        $this->newForm();

        $this->writer()->save(
            null,
            $this->definition(['schema' => $storedSchema, 'settings' => $storedSettings]),
            $this->allow('Furan_FormBuilder::submissions'),
            $source
        );
    }

    private static function fieldSchema(bool $personal, string $id = 'f_name'): array
    {
        return ['pages' => [['id' => 'p_1', 'items' => [
            ['kind' => 'field', 'id' => $id, 'name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'personal' => $personal],
        ]]]];
    }

    private function savedFormWithPersonalField(): Form&Stub
    {
        $existing = $this->chainable($this->createStub(Form::class));
        $existing->method('getSchema')->willReturn(self::fieldSchema(true));
        $existing->method('getId')->willReturn(5);
        $this->normalizer = $this->createStub(Normalizer::class);
        $this->normalizer->method('normalize')->willReturn(self::fieldSchema(false));
        $this->resource->method('getIdByIdentifier')->willReturn(5);
        return $existing;
    }

    public function testItRefusesAPersonalUntickWithoutTheWebhooksPersonalResourceAndLogsAnAllowedOneWithTheAdminId(): void
    {
        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->expects($this->never())->method('save');
        $existing = $this->savedFormWithPersonalField();

        $errors = $this->errorsOf(fn () => $this->writer()->save($existing, $this->definition(), $this->allow(self::PERSONAL)));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Full name', $errors[0]);

        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->expects($this->once())->method('save')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('info')->with(
            $this->anything(),
            ['admin_id' => 7, 'form_id' => 5, 'fields' => ['full_name']]
        );

        $this->writer()->save($existing, $this->definition(), $this->allow());
    }

    public function testItLogsAPersonalUntickWithoutAnAdminIdForTheCommandLine(): void
    {
        $existing = $this->savedFormWithPersonalField();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('info')->with(
            $this->anything(),
            ['admin_id' => null, 'form_id' => 5, 'fields' => ['full_name']]
        );

        $this->writer()->save($existing, $this->definition(), FormWriteContext::allowAll());
    }

    public function testItAllowsANewFieldThatIsNotPersonalWithoutThePersonalResource(): void
    {
        $existing = $this->chainable($this->createStub(Form::class));
        $existing->method('getSchema')->willReturn(self::fieldSchema(true));
        $this->normalizer = $this->createStub(Normalizer::class);
        $this->normalizer->method('normalize')->willReturn(array_replace_recursive(self::fieldSchema(true), [
            'pages' => [['items' => [1 => ['kind' => 'field', 'id' => 'f_new', 'name' => 'topic', 'label' => 'Topic', 'type' => 'text', 'personal' => false]]]],
        ]));
        $this->resource->method('getIdByIdentifier')->willReturn(null);

        $this->assertSame($existing, $this->writer()->save($existing, $this->definition(), $this->allow(self::PERSONAL)));
    }

    public function testItSavesTranslationsForKnownStoresOnly(): void
    {
        $this->normalizer = $this->createStub(Normalizer::class);
        $this->normalizer->method('normalize')->willReturn(['version' => 1, 'pages' => [['id' => 'p_abcd', 'title' => 'Page', 'items' => []]]]);
        $this->settingsNormalizer = $this->createStub(SettingsNormalizer::class);
        $this->settingsNormalizer->method('normalize')->willReturn(['submit_label' => 'Go']);
        $form = $this->createMock(Form::class);
        foreach (['setIdentifier', 'setTitle', 'setIsActive', 'setSchema', 'setSettings', 'setStoreIds'] as $m) {
            $form->method($m)->willReturnSelf();
        }
        $form->expects($this->once())->method('setTranslations')->with([
            2 => ['form' => ['submit_label' => ['t' => 'Aller', 's' => 'Go']]],
        ]);
        $this->factory->method('create')->willReturn($form);

        $this->writer()->save(null, $this->definition(['translations' => [
            '2' => ['form' => ['submit_label' => ['t' => 'Aller']]],
            '0' => ['form' => ['submit_label' => ['t' => 'Admin']]],
            '9' => ['form' => ['submit_label' => ['t' => 'Unknown store']]],
        ]]), $this->allow());
    }

    public function testItLeavesSavedTranslationsAloneWhenNoneAreGiven(): void
    {
        $form = $this->createMock(Form::class);
        foreach (['setIdentifier', 'setTitle', 'setIsActive', 'setSchema', 'setSettings', 'setStoreIds'] as $m) {
            $form->method($m)->willReturnSelf();
        }
        $form->expects($this->never())->method('setTranslations');
        $this->factory->method('create')->willReturn($form);

        $this->writer()->save(null, $this->definition(), $this->allow());
    }

    public function testItLetsExtensionsSaveThroughTheFormManagementInterface(): void
    {
        $root = __DIR__ . '/../../../..';
        $this->assertInstanceOf(\Furan\FormBuilder\Api\FormManagementInterface::class, $this->writer());
        $this->assertStringContainsString(
            '<preference for="Furan\FormBuilder\Api\FormManagementInterface" type="Furan\FormBuilder\Model\Form\FormWriter"/>',
            (string)file_get_contents($root . '/etc/di.xml')
        );
        $this->assertStringContainsString(
            'Api\FormManagementInterface',
            (string)file_get_contents($root . '/docs/extending.md')
        );
    }
}
