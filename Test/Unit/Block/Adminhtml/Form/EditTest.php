<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\ValidationRuleInterface;
use Furan\FormBuilder\Block\Adminhtml\Form\BuilderPhrases;
use Furan\FormBuilder\Block\Adminhtml\FieldGroup\Edit as GroupEdit;
use Furan\FormBuilder\Block\Adminhtml\Form\Edit;
use Furan\FormBuilder\Model\Admin\BuilderSharedConfig;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\SettingsNormalizer;
use Furan\FormBuilder\Model\Validation\RuleLibrary;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ArrayAwareOperator;
use Furan\FormBuilder\Test\Unit\Helper\ArraySettingsContributor;
use Furan\FormBuilder\Test\Unit\Helper\BackendBlockObjectManager;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use Furan\FormBuilder\Test\Unit\Helper\Translated;
use Magento\Customer\Model\Customer\Source\GroupSourceInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Furan\FormBuilder\Model\Admin\PreviewLink;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store as StoreModel;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\System\Store;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EditTest extends TestCase
{
    use BackendBlockObjectManager;

    private const TEMPLATE = __DIR__ . '/../../../../../view/adminhtml/templates/form/edit.phtml';
    private const KEY = 'furan_formbuilder_form';

    private FormRepositoryInterface&Stub $repository;
    private DataPersistorInterface&Stub $persistor;
    private PreviewLink&Stub $frontendUrl;
    private ?string $requestId = null;
    private array $persisted = [];
    private bool $mayViewSubmissions = true;
    private array $groupOptions = [];
    private array $extraOperators = [];
    private array $textSpec = [];
    private array $extraSources = [];
    private array $extraTypes = [];
    private array $allowedResources = [];
    private ?\Furan\FormBuilder\Model\Settings\ContributorPool $contributors = null;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?DataPersistorInterface $persistor = null, ?FormRepositoryInterface $repository = null): void
    {
        $this->repository = $repository ?? $this->createStub(FormRepositoryInterface::class);
        $this->persistor = $persistor ?? $this->createStub(DataPersistorInterface::class);
        $this->frontendUrl = $this->createStub(PreviewLink::class);
        $this->requestId = null;
        $this->persisted = [];
        $this->mayViewSubmissions = true;
        $this->extraOperators = [];
        $this->textSpec = [];
        $this->extraSources = [];
        $this->extraTypes = [];
        $this->allowedResources = [];
        $this->contributors = null;
        $this->groupOptions = [
            ['label' => 'ALL GROUPS', 'value' => '32000'],
            ['label' => 'NOT LOGGED IN', 'value' => '0'],
            ['label' => 'General', 'value' => '1'],
        ];
    }

    public function testItBuildsTheBuilderConfigWithFieldTypesRulesAndFormData(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(persistor: $persistor, repository: $repository);
        $frontendUrl = $this->frontendUrl = $this->createMock(PreviewLink::class);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getIdentifier')->willReturn('contact');
        $form->method('getTitle')->willReturn('Contact');
        $form->method('isActive')->willReturn(false);
        $form->method('getStoreIds')->willReturn([2]);
        $form->method('getSchema')->willReturn(['version' => 1, 'pages' => [['id' => 'p_abcd']]]);
        $form->method('getSettings')->willReturn(['submit_label' => 'Go']);
        $this->requestId = '7';
        $repository->method('getById')->with(7)->willReturn($form);
        $frontendUrl->method('url')->with(7, 'contact', false, 2)->willReturn('https://shop.test/forms/contact');

        $config = $this->block()->getBuilderConfig();

        $this->assertSame([
            'id' => 7, 'identifier' => 'contact', 'title' => 'Contact', 'is_active' => false,
            'store_ids' => [2], 'schema' => ['version' => 1, 'pages' => [['id' => 'p_abcd']]],
            'settings' => ['submit_label' => 'Go'], 'translations' => [],
        ], $config['form']);
        $this->assertSame([[
            'code' => 'text', 'label' => 'Text', 'category' => 'input', 'allowedRules' => ['min_length'],
            'settingsSpec' => [], 'hasOptions' => false, 'isFile' => false, 'isMultiValue' => false, 'emailAddress' => false, 'defaults' => [], 'relativeDate' => null, 'routable' => true,
            'personalByDefault' => false, 'conditionSource' => true,
            'controls' => ['default' => 'text'], 'presets' => [], 'maxLength' => null,
            'consent' => false, 'serverValue' => false, 'requiredAllowed' => true, 'draftAllowed' => true, 'defaultAllowed' => true,
        ]], $config['fieldTypes']);
        $this->assertSame([['code' => 'min_length', 'label' => 'Minimum length', 'valueType' => 'int']], $config['rules']);
        $this->assertCount(14, $config['operators']);
        $this->assertSame(['heading', 'paragraph', 'divider', 'spacer', 'rich_text', 'notice', 'summary'], array_column($config['contentTypes'], 'code'));
        $this->assertSame(['full', 'two_thirds', 'half', 'third'], array_column($config['widths'], 'value'));
        $this->assertContains('given-name', $config['autocomplete']);
        $this->assertSame([
            ['value' => 0, 'label' => 'All Store Views'],
            ['value' => 1, 'label' => 'Main Website / Main Store / Default Store View'],
        ], $config['stores']);
        $this->assertSame(['allowedExtensions' => ['pdf'], 'maxMb' => 5], $config['uploads']);
        $this->assertCount(17, $config['prefillSources']);
        $this->assertSame(
            ['code' => 'customer.email', 'label' => 'Customer email', 'group' => 'customer', 'types' => ['text']],
            $config['prefillSources'][8]
        );
        $this->assertSame('admin:*/*/save', $config['urls']['save']);
        $this->assertSame('admin:*/*/index', $config['urls']['back']);
        $this->assertSame('https://shop.test/forms/contact', $config['urls']['preview']);
        $this->assertSame([], $config['errors']);
    }

    public function testItBuildsTheAdminPreviewUrlForTheFormsStore(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $previews = [];
        $this->frontendUrl->method('url')->willReturnCallback(
            function (int $formId, string $identifier, bool $active, int $storeId) use (&$previews): string {
                $previews[] = [$identifier, $active, $storeId];
                return 'https://shop.test/p';
            }
        );
        foreach ([[[0], true], [[], false], [[2, 1], true]] as [$storeIds, $active]) {
            $form = $this->createStub(FormInterface::class);
            $form->method('getId')->willReturn(7);
            $form->method('getIdentifier')->willReturn('contact');
            $form->method('isActive')->willReturn($active);
            $form->method('getStoreIds')->willReturn($storeIds);
            $repository = $this->createStub(FormRepositoryInterface::class);
            $repository->method('getById')->willReturn($form);
            $this->repository = $repository;
            $this->requestId = '7';
            $this->block()->getBuilderConfig();
        }

        $this->assertSame([['contact', true, 1], ['contact', false, 1], ['contact', true, 2]], $previews);
    }

    public function testItOffersNoPreviewWhenTheFormsStoreViewIsGone(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getIdentifier')->willReturn('contact');
        $form->method('getStoreIds')->willReturn([9]);
        $this->repository->method('getById')->willReturn($form);
        $this->requestId = '7';
        $this->frontendUrl->method('url')->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());

        $this->assertNull($this->block()->getBuilderConfig()['urls']['preview']);
    }

    public function testItExposesWhetherTheAdminMayChangeNotificationSettings(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->assertTrue($this->block()->getBuilderConfig()['permissions']['notifications']);

        $this->mayViewSubmissions = false;
        $this->assertFalse($this->block()->getBuilderConfig()['permissions']['notifications']);
    }

    public function testItLeavesSubmissionSettingsOutOfTheBuilderConfigWithoutSubmissionRights(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(persistor: $persistor, repository: $repository);
        $this->savedFormWithSettings([
            'submit_label' => 'Go', 'notify_enabled' => true, 'notify_recipients' => ['ops@x.test'],
            'notify_cc' => ['cc@x.test'], 'notify_bcc' => ['bcc@x.test'], 'notify_subject' => 'Hi',
            'notify_routes' => [['emails' => ['r@x.test']]], 'notify_when' => ['rules' => []], 'store_ip' => true,
        ]);
        $this->mayViewSubmissions = true;
        $this->assertArrayHasKey('notify_recipients', $this->block()->getBuilderConfig()['form']['settings']);

        $this->mayViewSubmissions = false;
        $this->assertSame(['submit_label' => 'Go'], $this->block()->getBuilderConfig()['form']['settings']);
    }

    public function testItOnlyGivesTheBuilderGroupActionsTheAdminMayUse(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $none = $this->block()->getBuilderConfig();
        $this->assertSame([], $none['fieldGroups']);
        $this->assertFalse($none['permissions']['field_groups']);
        $this->assertFalse($none['permissions']['field_groups_edit']);

        $this->allowedResources = ['Furan_FormBuilder::field_groups'];
        $viewOnly = $this->block()->getBuilderConfig();
        $this->assertSame(['list' => 'admin:*/fieldgroup/palette', 'insert' => 'admin:*/fieldgroup/insert'], $viewOnly['fieldGroups']);
        $this->assertTrue($viewOnly['permissions']['field_groups']);
        $this->assertFalse($viewOnly['permissions']['field_groups_edit']);

        $this->allowedResources = ['Furan_FormBuilder::field_groups_edit'];
        $editOnly = $this->block()->getBuilderConfig();
        $this->assertSame([], $editOnly['fieldGroups'], 'Create needs both rights, like the endpoint');
        $this->assertTrue($editOnly['permissions']['field_groups_edit']);

        $this->allowedResources = ['Furan_FormBuilder::field_groups', 'Furan_FormBuilder::field_groups_edit'];
        $both = $this->block()->getBuilderConfig();
        $this->assertSame('admin:*/fieldgroup/create', $both['fieldGroups']['create']);
    }

    public function testItOpensAFormWithANewerSchemaReadOnlyWithAWarning(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(persistor: $persistor, repository: $repository);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getSchema')->willReturn(['version' => 99, 'pages' => []]);
        $this->requestId = '7';
        $repository->method('getById')->with(7)->willReturn($form);

        $config = $this->block()->getBuilderConfig();

        $this->assertFalse($config['permissions']['save']);
        $this->assertCount(1, $config['errors']);
        $this->assertStringContainsString('99', $config['errors'][0]);
    }

    public function testItDefaultsANewFormToOnePageAndNormalisedSettings(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $config = $this->block()->getBuilderConfig();

        $this->assertNull($config['form']['id']);
        $this->assertSame([0], $config['form']['store_ids']);
        $this->assertTrue($config['form']['is_active']);
        $this->assertCount(1, $config['form']['schema']['pages']);
        $this->assertSame('Page 1', $config['form']['schema']['pages'][0]['title']);
        $this->assertSame('Submit', $config['form']['settings']['submit_label']);
        $this->assertNull($config['urls']['preview']);
        $this->assertSame('', $config['urls']['duplicate']);
        $this->assertSame('', $config['urls']['delete']);
        $this->assertSame([], $config['form']['translations']);
    }

    public function testItTranslatesTheFirstPageTitleOfANewForm(): void
    {
        $this->fixture(persistor: $this->createMock(DataPersistorInterface::class));

        $config = Translated::with('T:', fn (): array => $this->block()->getBuilderConfig());

        $this->assertSame('T:Page 1', $config['form']['schema']['pages'][0]['title']);
    }

    public function testItPrefersPersistedDataAndErrorsFromAFailedSave(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->persisted = [
            'form_id' => '', 'identifier' => 'typed', 'title' => 'Typed', 'is_active' => '0',
            'store_ids' => '[1]', 'schema' => '{"version":1,"pages":[{"id":"p_zzzz"}]}',
            'settings' => '{"submit_label":"Send"}', 'errors' => ['Bad thing'],
            'translations' => '{"2":{"form":{"submit_label":{"t":"Envoyer","s":"Send"}}}}',
        ];
        $persistor->expects($this->once())->method('clear')->with(self::KEY);

        $config = $this->block()->getBuilderConfig();

        $this->assertSame('typed', $config['form']['identifier']);
        $this->assertSame('Typed', $config['form']['title']);
        $this->assertFalse($config['form']['is_active']);
        $this->assertSame([1], $config['form']['store_ids']);
        $this->assertSame([['id' => 'p_zzzz']], $config['form']['schema']['pages']);
        $this->assertSame(['submit_label' => 'Send'], $config['form']['settings']);
        $this->assertSame(['Bad thing'], $config['errors']);
        $this->assertSame([2 => ['form' => ['submit_label' => ['t' => 'Envoyer', 's' => 'Send']]]], $config['form']['translations']);
    }

    public function testItListsActiveStoreViewsWithLocalesAllStoreCodesTheDuplicateUrlAndBuilderPhrases(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(persistor: $persistor, repository: $repository);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getIdentifier')->willReturn('contact');
        $form->method('getStoreIds')->willReturn([0]);
        $form->method('getSchema')->willReturn(['version' => 1, 'pages' => []]);
        $form->method('getTranslations')->willReturn([2 => ['form' => []]]);
        $this->requestId = '7';
        $repository->method('getById')->with(7)->willReturn($form);

        $config = $this->block()->getBuilderConfig();

        $this->assertSame([
            ['id' => 1, 'code' => 'default', 'name' => 'English', 'locale' => 'en_GB'],
            ['id' => 2, 'code' => 'fr', 'name' => 'French', 'locale' => 'fr_FR'],
        ], $config['translationStores']);
        $this->assertSame([1 => 'default', 2 => 'fr', 3 => 'old'], $config['storeCodes']);
        $this->assertSame([2 => ['form' => []]], $config['form']['translations']);
        $this->assertSame('admin:*/*/duplicate', $config['urls']['duplicate']);
        $this->assertSame('', $config['urls']['delete'], 'deleting needs its own permission');
        $this->assertSame('Save', $config['phrases']['Save']);
    }

    public function testItOffersDeleteForASavedFormToAdminsWhoMayDeleteForms(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(persistor: $this->createMock(DataPersistorInterface::class), repository: $repository);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getStoreIds')->willReturn([0]);
        $form->method('getSchema')->willReturn(['version' => 1, 'pages' => []]);
        $this->requestId = '7';
        $repository->method('getById')->with(7)->willReturn($form);
        $this->allowedResources = ['Furan_FormBuilder::forms_delete'];

        $this->assertSame('admin:*/*/delete', $this->block()->getBuilderConfig()['urls']['delete']);
    }

    public function testItEncodesTheBuilderConfigSafelyForHtml(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->persisted = ['title' => '</script><img src=x onerror=1>&"\'', 'errors' => []];

        $json = $this->block()->getConfigJson();

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $this->assertSame(
            '</script><img src=x onerror=1>&"\'',
            json_decode($json, true)['form']['title']
        );
    }

    public function testItPrintsTheFormIdAndTheThreeBuilderScriptsInTheEditTemplate(): void
    {
        $block = $this->createStub(Edit::class);
        $block->method('getFormId')->willReturn('7"><x');
        $block->method('getSaveUrl')->willReturn('https://admin.test/save?a=1&b=2');
        $block->method('getFormKey')->willReturn('KEY123');
        $block->method('getConfigJson')->willReturn('{"a":"<"}');
        $block->method('getViewFileUrl')->willReturnCallback(static fn(string $f): string => 'https://static.test/' . $f);
        $escaper = EscaperFactory::create();

        ob_start();
        include self::TEMPLATE;
        $html = (string)ob_get_clean();

        $this->assertStringContainsString('name="form_id" value="7&quot;&gt;&lt;x"', $html);
        $this->assertStringContainsString('name="form_key" value="KEY123"', $html);
        $this->assertStringContainsString('id="ffb-admin-form"', $html);
        $this->assertStringContainsString('data-ffb-builder', $html);
        $this->assertStringContainsString('<script type="application/json" id="ffb-builder-config">{"a":"<"}</script>', $html);
        $registry = strpos($html, 'js/builder-registry.js');
        $this->assertNotFalse($registry);
        $this->assertLessThan(strpos($html, 'js/builder-model.js'), $registry);
        $model = strpos($html, 'js/builder-model.js');
        $inspector = strpos($html, 'js/builder-inspector.js');
        $builder = strpos($html, 'js/builder.js');
        $this->assertNotFalse($model);
        $this->assertGreaterThan($model, $inspector);
        $this->assertGreaterThan($inspector, $builder);
        foreach (['identifier', 'title', 'is_active', 'store_ids', 'schema', 'settings', 'back'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html);
        }
        $this->assertStringNotContainsString('<script>', $html);
    }

    /** @var array<int, array<string, mixed>> */
    private array $libraryEntries = [];

    private function block(): Edit
    {
        [$context, $shared] = $this->builderParts();

        return new Edit(
            $context,
            $shared,
            $this->repository,
            $this->settingsNormalizer(),
            $this->persistor,
            $this->frontendUrl,
            new Json(),
            new JsonHexTag(new Json()),
            $this->contributors ?? ArraySettingsContributor::pool(),
            new \Furan\FormBuilder\Model\Schema\SchemaMigrator($this->createStub(\Psr\Log\LoggerInterface::class)),
            $this->scheduleTimezone(),
            new \Furan\FormBuilder\Model\Settings\ProtectedSettings($this->contributors ?? ArraySettingsContributor::pool())
        );
    }

    private function groupBlock(): GroupEdit
    {
        [$context, $shared] = $this->builderParts();

        return new GroupEdit($context, $shared, new JsonHexTag(new Json()));
    }

    private function settingsNormalizer(): SettingsNormalizer
    {
        $settings = $this->createStub(SettingsNormalizer::class);
        $settings->method('normalize')->willReturn(['submit_label' => 'Submit']);
        return $settings;
    }

    /** @return array{Context, BuilderSharedConfig} */
    private function builderParts(): array
    {
        $library = $this->createStub(RuleLibrary::class);
        $library->method('all')->willReturn($this->libraryEntries);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('id')->willReturn($this->requestId);
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(static fn(string $p): string => 'admin:' . $p);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $this->persistor->method('get')->with(self::KEY)->willReturn($this->persisted);

        $text = $this->createStub(FieldTypeInterface::class);
        $text->method('getCode')->willReturn('text');
        $text->method('getLabel')->willReturn('Text');
        $text->method('getCategory')->willReturn('input');
        $text->method('getAllowedRules')->willReturn(['min_length']);
        $text->method('getSettingsSpec')->willReturn($this->textSpec);
        $text->method('hasOptions')->willReturn(false);
        $text->method('isFile')->willReturn(false);
        $text->method('isMultiValue')->willReturn(false);
        $rule = $this->createStub(ValidationRuleInterface::class);
        $rule->method('getCode')->willReturn('min_length');
        $rule->method('getLabel')->willReturn('Minimum length');
        $rule->method('getValueType')->willReturn('int');

        $config = $this->createStub(Config::class);
        $config->method('getRoutePrefix')->willReturn('forms');
        $config->method('getAllowedExtensions')->willReturn(['pdf']);
        $config->method('getMaxUploadMb')->willReturn(5);

        $systemStore = $this->createMock(Store::class);
        $systemStore->method('getStoreValuesForForm')->with(false, true)->willReturn([
            ['label' => 'All Store Views', 'value' => 0],
            ['label' => 'Main Website', 'value' => []],
            ['label' => "\u{00A0}\u{00A0}Main Store", 'value' => [
                ['label' => "\u{00A0}\u{00A0}Default Store View", 'value' => '1'],
            ]],
        ]);
        $defaultStore = $this->createStub(StoreInterface::class);
        $defaultStore->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $stores = [];
        foreach ([[1, 'default', 'English', true], [2, 'fr', 'French', true], [3, 'old', 'Old', false]] as [$id, $code, $name, $active]) {
            $store = $this->createStub(StoreModel::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn($code);
            $store->method('getName')->willReturn($name);
            $store->method('isActive')->willReturn($active);
            $stores[] = $store;
        }
        $storeManager->method('getStores')->willReturn($stores);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path, string $scope, $storeId): string => $storeId === 2 ? 'fr_FR' : 'en_GB'
        );
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            fn(string $resource): bool => ($resource === 'Furan_FormBuilder::submissions' && $this->mayViewSubmissions)
                || in_array($resource, $this->allowedResources, true)
        );

        $groupSource = $this->createStub(GroupSourceInterface::class);
        $groupSource->method('toOptionArray')->willReturn($this->groupOptions);

        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getAuthorization')->willReturn($authorization);
        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('KEY123');
        $context->method('getFormKey')->willReturn($formKey);

        return [$context, new BuilderSharedConfig(
            new Pool(['text' => $text] + $this->extraTypes),
            new RulePool(['min_length' => $rule]),
            $library,
            OperatorPools::builtIn($this->extraOperators),
            \Furan\FormBuilder\Test\Unit\Helper\ActionPools::builtIn(),
            new \Furan\FormBuilder\Model\FieldType\SettingLabels(),
            \Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools::builtIn($this->extraSources),
            $groupSource,
            $systemStore,
            $storeManager,
            $scopeConfig,
            new BuilderPhrases(),
            $config,
            new \Furan\FormBuilder\Model\Validation\PresetFit()
        )];
    }

    private function scheduleTimezone(): \Furan\FormBuilder\Model\Form\ScheduleTimezone
    {
        $schedule = $this->createStub(\Furan\FormBuilder\Model\Form\ScheduleTimezone::class);
        $schedule->method('storeZones')->willReturn([1 => 'Europe/London', 2 => 'Asia/Tokyo']);
        $schedule->method('defaultScope')->willReturn('America/Chicago');
        $schedule->method('defaultFor')->willReturn('Europe/London');
        $schedule->method('options')->willReturnCallback(
            static fn (array $inUse): array => array_map(static fn (string $z): array => ['value' => $z, 'label' => $z], $inUse)
        );
        return $schedule;
    }

    private function savedFormWithSettings(array $settings, array $storeIds = [1]): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getStoreIds')->willReturn($storeIds);
        $form->method('getSchema')->willReturn(['version' => 1, 'pages' => [['id' => 'p_abcd']]]);
        $form->method('getSettings')->willReturn($settings);
        $this->requestId = '7';
        $this->repository->method('getById')->with(7)->willReturn($form);
    }

    public function testItShowsTheStoredTimesInTheBuilderInTheTimezoneTheyWereEnteredIn(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(persistor: $persistor, repository: $repository);
        $this->savedFormWithSettings([
            'open_from' => '2026-07-01T09:00:00Z', 'close_at' => '2026-12-01T09:00:00Z', 'schedule_timezone' => 'Asia/Tokyo',
        ]);

        $schedule = $this->block()->getBuilderConfig()['schedule'];

        $this->assertSame(['open_from' => '2026-07-01T18:00', 'close_at' => '2026-12-01T18:00'], $schedule['local']);
        $this->assertSame([1 => 'Europe/London', 2 => 'Asia/Tokyo'], $schedule['storeTimezones']);
        $this->assertSame('America/Chicago', $schedule['defaultTimezone']);
        $this->assertContains('Asia/Tokyo', array_column($schedule['timezones'], 'value'));
    }

    public function testItShowsTimesFromAFailedSaveAsTyped(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(persistor: $persistor, repository: $repository);
        $this->savedFormWithSettings([]);
        $this->persisted = ['settings' => json_encode(['open_from' => '2026-07-01T10:00', 'schedule_timezone' => 'Asia/Tokyo'])];

        $this->assertSame('2026-07-01T10:00', $this->block()->getBuilderConfig()['schedule']['local']['open_from']);
    }

    public function testItAddsTheBuiltInSettingLabelToEachFieldTypeSpecInTheBuilderConfig(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->textSpec = ['rows' => ['type' => 'int', 'default' => 5], 'x_custom' => ['type' => 'int']];

        $spec = $this->block()->getBuilderConfig()['fieldTypes'][0]['settingsSpec'];

        $this->assertSame('Rows', $spec['rows']['label']);
        $this->assertArrayNotHasKey('label', $spec['x_custom']);
    }

    public function testItOffersTheMaskPresetsWithTheirLibraryLabels(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $entry = static fn (string $ref, string $label): array => [
            'ref' => $ref, 'label' => $label, 'group' => 'G', 'example' => '', 'message' => '', 'saved' => false, 'pattern' => 'x',
        ];
        $this->libraryEntries = [$entry('uk_postcode', 'UK postcode'), $entry('full_name', 'Full name'), $entry('uk_sort_code', 'UK sort code')];

        $this->assertSame(
            [['code' => 'uk_postcode', 'label' => 'UK postcode'], ['code' => 'uk_sort_code', 'label' => 'UK sort code']],
            $this->block()->getBuilderConfig()['masks']
        );
    }

    public function testItHidesTheNewsletterFieldFromThePaletteWhenTheNewsletterModuleIsDisabled(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = ['newsletter' => $this->newsletterType(false)];

        $codes = array_column($this->block()->getBuilderConfig()['fieldTypes'], 'code');

        $this->assertSame(['text'], $codes);
    }

    public function testItOffersTheNewsletterFieldWhenTheNewsletterModuleIsEnabled(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = ['newsletter' => $this->newsletterType(true)];

        $types = $this->block()->getBuilderConfig()['fieldTypes'];

        $this->assertSame(['text', 'newsletter'], array_column($types, 'code'));
        $this->assertSame(
            ['label' => 'Sign me up for the newsletter', 'draft' => false],
            $types[1]['defaults']
        );
        $this->assertSame([], $types[0]['defaults']);
    }

    private function newsletterType(bool $available): \Furan\FormBuilder\Model\FieldType\Newsletter
    {
        $bridge = $this->createStub(\Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn($available);

        return new \Furan\FormBuilder\Model\FieldType\Newsletter($bridge);
    }

    public function testItListsCustomerGroupsIncludingNotLoggedInInTheBuilderConfig(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $groups = $this->block()->getBuilderConfig()['customerGroups'];

        $this->assertContains(['value' => 0, 'label' => 'NOT LOGGED IN'], $groups);
        $this->assertContains(['value' => 1, 'label' => 'General'], $groups);
    }

    public function testItLeavesTheAllGroupsEntryOutOfTheBuilderGroups(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $groups = $this->block()->getBuilderConfig()['customerGroups'];

        $this->assertSame([0, 1], array_column($groups, 'value'));
    }

    public function testItListsPoolOperatorsWithLabelsInTheBuilderConfigFilteredByFieldType(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraOperators = [
            'sounds_like' => new ArrayAwareOperator('sounds_like', ['date']),
            'text_only' => new ArrayAwareOperator('text_only', ['text']),
        ];

        $operators = array_column($this->block()->getBuilderConfig()['operators'], null, 'code');

        $this->assertCount(16, $operators);
        $this->assertSame(
            ['code' => 'is_empty', 'label' => 'is empty', 'needsValue' => false, 'types' => ['text']],
            $operators['is_empty']
        );
        $this->assertTrue($operators['equals']['needsValue']);
        $this->assertSame([], $operators['sounds_like']['types']);
        $this->assertSame(['text'], $operators['text_only']['types']);
    }

    public function testItTreatsAnyConsentTypeAsNeverRequiredBlankedUnderLoginAsCustomerNotDraftableAndRefusedHideAndRequireActionsInTheBuilder(): void
    {
        $this->fixture(persistor: $this->createMock(DataPersistorInterface::class));
        $this->extraTypes = [\Furan\FormBuilder\Test\Unit\Helper\SmsConsentType::CODE => new \Furan\FormBuilder\Test\Unit\Helper\SmsConsentType()];

        $actions = array_column($this->block()->getBuilderConfig()['actions'], null, 'code');

        $this->assertContains('sms_optin', $actions['show']['types']);
        $this->assertNotContains('sms_optin', $actions['require']['types']);
        $this->assertNotContains('sms_optin', $actions['set_value']['types']);
    }

    public function testItListsConditionActionsWithTargetsParameterKindAndTheFieldTypesTheyFit(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = [
            'date' => new \Furan\FormBuilder\Model\FieldType\Date(),
            'select' => new \Furan\FormBuilder\Model\FieldType\Select(),
            'checkboxes' => new \Furan\FormBuilder\Model\FieldType\Checkboxes(),
            'newsletter' => $this->newsletterType(true),
        ];

        $actions = array_column($this->block()->getBuilderConfig()['actions'], null, 'code');

        $this->assertSame(['show', 'hide', 'require', 'set_value', 'hide_options'], array_keys($actions));
        $this->assertSame(['page', 'section', 'field', 'content'], $actions['show']['targets']);
        $this->assertSame(['field'], $actions['require']['targets']);
        $this->assertNull($actions['require']['param']);
        $this->assertSame('value', $actions['set_value']['param']);
        $this->assertSame('options', $actions['hide_options']['param']);
        $this->assertSame('Set value', $actions['set_value']['label']);
        $this->assertSame(['text', 'date', 'select', 'checkboxes', 'newsletter'], $actions['show']['types']);
        $this->assertSame(['text', 'date', 'select', 'checkboxes'], $actions['require']['types']);
        $this->assertSame(['text', 'date', 'select'], $actions['set_value']['types']);
        $this->assertSame(['select', 'checkboxes'], $actions['hide_options']['types']);
    }

    public function testItSendsThePersonalByDefaultFlagForEachFieldType(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = [
            'email' => new \Furan\FormBuilder\Model\FieldType\Email(),
            'file' => new \Furan\FormBuilder\Model\FieldType\File(),
            'select' => new \Furan\FormBuilder\Model\FieldType\Select(),
        ];

        $types = array_column($this->block()->getBuilderConfig()['fieldTypes'], 'personalByDefault', 'code');

        $this->assertSame(['text' => false, 'email' => true, 'file' => true, 'select' => false], $types);
    }

    public function testItSendsTheEmailAddressFlagForEachFieldType(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = [
            'email' => new \Furan\FormBuilder\Model\FieldType\Email(),
            \Furan\FormBuilder\Test\Unit\Helper\WorkEmailType::CODE => new \Furan\FormBuilder\Test\Unit\Helper\WorkEmailType(),
        ];

        $types = array_column($this->block()->getBuilderConfig()['fieldTypes'], 'emailAddress', 'code');

        $this->assertSame(['text' => false, 'email' => true, 'work_email' => true], $types);
    }

    public function testItSendsTheConditionSourceFlagForEachFieldType(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = [
            'date_range' => new \Furan\FormBuilder\Model\FieldType\DateRange(),
            'matrix' => new \Furan\FormBuilder\Model\FieldType\Matrix(),
            'select' => new \Furan\FormBuilder\Model\FieldType\Select(),
        ];

        $types = array_column($this->block()->getBuilderConfig()['fieldTypes'], 'conditionSource', 'code');

        $this->assertSame(['text' => true, 'date_range' => false, 'matrix' => false, 'select' => true], $types);
    }

    public function testItSendsThePersonalAutocompleteListFromThePersonalDataModel(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);

        $config = $this->block()->getBuilderConfig();

        $this->assertSame(\Furan\FormBuilder\Model\Schema\PersonalData::PERSONAL_AUTOCOMPLETE, $config['personalAutocomplete']);
    }

    public function testItFlagsFieldTypesThatCannotBeRoutedFromTheirCapabilities(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = [
            'file' => new \Furan\FormBuilder\Model\FieldType\File(),
            'date_range' => new \Furan\FormBuilder\Model\FieldType\DateRange(),
            'select' => new \Furan\FormBuilder\Model\FieldType\Select(),
            'custom' => new \Furan\FormBuilder\Test\Unit\Helper\AdminViewKindType('custom', null),
        ];

        $types = array_column($this->block()->getBuilderConfig()['fieldTypes'], 'routable', 'code');

        $this->assertSame(['text' => true, 'file' => false, 'date_range' => false, 'select' => true, 'custom' => true], $types);
    }

    public function testItPassesTheLabelsOfAnEnumSettingsValuesToTheBuilder(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->textSpec = ['source' => [
            'type' => 'enum', 'default' => 'a', 'values' => ['a', 'b'], 'value_labels' => ['a' => 'Alpha'],
        ]];

        $spec = $this->block()->getBuilderConfig()['fieldTypes'][0]['settingsSpec'];

        $this->assertSame(['a' => 'Alpha'], $spec['source']['value_labels']);
    }

    public function testItMarksDateLikeFieldTypesWithTheirRelativeDateFormat(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraTypes = [
            'date' => new \Furan\FormBuilder\Model\FieldType\Date(),
            'month' => new \Furan\FormBuilder\Model\FieldType\Month(),
        ];

        $types = array_column($this->block()->getBuilderConfig()['fieldTypes'], 'relativeDate', 'code');

        $this->assertSame(['text' => null, 'date' => 'Y-m-d', 'month' => 'Y-m'], $types);
    }

    public function testItExposesTheConditionLimitsTheServerEnforces(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->assertSame(
            ['maxLevels' => 3, 'maxEntries' => 25, 'maxRelativeDays' => 3650, 'maxActions' => 10],
            $this->block()->getBuilderConfig()['conditionLimits']
        );
    }

    public function testItRendersABuilderPanelForEachContributorFromItsSpec(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $open = new ArraySettingsContributor('open_ext');
        $guarded = new ArraySettingsContributor('acme_crm', ['k' => ['type' => 'string', 'default' => '', 'label' => 'K']], 'Acme_Crm::settings');
        $this->contributors = ArraySettingsContributor::pool($open, $guarded);

        $panels = $this->block()->getBuilderConfig()['settingsContributors'];

        $this->assertSame(
            ['code' => 'open_ext', 'label' => 'Acme CRM', 'spec' => $open->getSpec(), 'readOnly' => false],
            $panels[0]
        );
        $this->assertSame('acme_crm', $panels[1]['code']);
        $this->assertTrue($panels[1]['readOnly']);

        $this->allowedResources = ['Acme_Crm::settings'];
        $this->assertFalse($this->block()->getBuilderConfig()['settingsContributors'][1]['readOnly']);
    }

    public function testItListsPoolSourcesWithLabelsAndGroupsInTheBuilder(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $this->extraSources = [
            'product.stock' => new \Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource('product.stock', 'product', ['text', 'date']),
            'customer.loyalty' => new \Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource('customer.loyalty', 'customer', ['date']),
        ];

        $sources = array_column($this->block()->getBuilderConfig()['prefillSources'], null, 'code');

        $this->assertCount(19, $sources);
        $this->assertSame(
            ['code' => 'product.stock', 'label' => 'Loyalty number', 'group' => 'product', 'types' => ['text']],
            $sources['product.stock']
        );
        $this->assertSame([], $sources['customer.loyalty']['types']);
        $this->assertSame('product', $sources['product.sku']['group']);
    }

    public function testTheFieldGroupBlockSharesTheBuilderSetupWithoutLoadingAForm(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor, repository: $repository);
        $this->requestId = '7';
        $repository->expects($this->never())->method('getById');
        $persistor->expects($this->never())->method('get');
        $editor = $this->createStub(\Furan\FormBuilder\ViewModel\FieldGroupEditor::class);
        $groupForm = ['id' => 7, 'title' => 'Contact', 'schema' => ['pages' => [['id' => 'p_group000', 'items' => []]]]];
        $editor->method('getForm')->willReturn($groupForm);
        $editor->method('canEdit')->willReturn(true);
        $editor->method('getSaveUrl')->willReturn('admin:group/save');
        $editor->method('getBackUrl')->willReturn('admin:group/index');
        $editor->method('getFieldGroupUrls')->willReturn(['list' => 'admin:palette', 'insert' => 'admin:insert']);
        $editor->method('getUsage')->willReturn(['rows' => [['title' => 'Contact', 'url' => '/f/3']], 'total' => 1]);
        $editor->method('getDeleteUrl')->willReturn('admin:group/delete');
        $block = $this->groupBlock();
        $block->setData('editor', $editor);

        $config = $block->getBuilderConfig();

        $this->assertSame($groupForm, $config['form']);
        $this->assertSame('text', $config['fieldTypes'][0]['code']);
        $this->assertArrayHasKey('operators', $config);
        $this->assertArrayHasKey('translationStores', $config);
        $this->assertArrayNotHasKey('schedule', $config);
        $this->assertSame([], $config['settingsContributors']);
        $this->assertSame(['save' => 'admin:group/save', 'back' => 'admin:group/index', 'delete' => ''], $config['urls']);
        $this->assertSame(['usage' => ['rows' => [['title' => 'Contact', 'url' => '/f/3']], 'total' => 1]], $config['group']);
        $this->assertSame(['list' => 'admin:palette', 'insert' => 'admin:insert'], $config['fieldGroups']);
        $this->assertSame(['save' => true, 'notifications' => false], $config['permissions']);
    }

    public function testTheFieldGroupBlockOffersDeleteOnlyWithTheDeletePermission(): void
    {
        $this->fixture(persistor: $this->createMock(DataPersistorInterface::class));
        $editor = $this->createStub(\Furan\FormBuilder\ViewModel\FieldGroupEditor::class);
        $editor->method('getForm')->willReturn([]);
        $editor->method('getUsage')->willReturn(['rows' => [], 'total' => 0]);
        $editor->method('canDelete')->willReturn(true);
        $editor->method('getDeleteUrl')->willReturn('admin:group/delete');
        $block = $this->groupBlock();
        $block->setData('editor', $editor);

        $this->assertSame('admin:group/delete', $block->getBuilderConfig()['urls']['delete']);
    }

    public function testTheFieldGroupBlockNeedsItsEditor(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $this->fixture(persistor: $persistor);
        $persistor->expects($this->never())->method('get');
        $this->expectException(\LogicException::class);
        $this->groupBlock()->getBuilderConfig();
    }
}
