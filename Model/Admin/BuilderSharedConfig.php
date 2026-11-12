<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Admin;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
use Furan\FormBuilder\Api\FieldType\DefaultValueInterface;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;
use Furan\FormBuilder\Api\FieldType\EmailAddressTypeInterface;
use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Api\FieldType\RelativeDateSourceInterface;
use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Block\Adminhtml\Form\BuilderPhrases;
use Furan\FormBuilder\Model\Condition\ActionPool;
use Furan\FormBuilder\Model\Condition\ConditionNormalizer;
use Furan\FormBuilder\Model\Condition\OperatorPool;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\SettingLabels;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\ContentTypes;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Validation\Mask;
use Furan\FormBuilder\Model\Validation\PresetFit;
use Furan\FormBuilder\Model\Validation\RuleLibrary;
use Furan\FormBuilder\Model\Validation\RulePool;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Model\Customer\Source\GroupSourceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\System\Store;

/**
 * The builder config that does not depend on the form or field group being edited.
 */
class BuilderSharedConfig
{
    private const WIDTHS = [
        'full' => 'Full',
        'two_thirds' => 'Two thirds',
        'half' => 'Half',
        'third' => 'Third',
    ];
    private const AUTOCOMPLETE = [
        '', 'name', 'given-name', 'family-name', 'email', 'tel', 'organization', 'street-address',
        'address-line1', 'address-line2', 'address-level1', 'address-level2', 'postal-code', 'country',
        'country-name', 'bday', 'url', 'off',
    ];

    public function __construct(
        private readonly Pool $fieldTypePool,
        private readonly RulePool $rulePool,
        private readonly RuleLibrary $ruleLibrary,
        private readonly OperatorPool $operatorPool,
        private readonly ActionPool $actionPool,
        private readonly SettingLabels $settingLabels,
        private readonly Sources $sources,
        private readonly GroupSourceInterface $groupSource,
        private readonly Store $systemStore,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly BuilderPhrases $phrases,
        private readonly Config $moduleConfig,
        private readonly PresetFit $presetFit
    ) {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return [
            'fieldTypes' => $this->fieldTypes(),
            'contentTypes' => $this->options(ContentTypes::labels(), 'code'),
            'rules' => $this->rules(),
            'ruleLibrary' => array_map(
                static fn (array $e) => array_intersect_key($e, array_flip(['ref', 'label', 'group', 'example', 'message', 'saved'])),
                $this->ruleLibrary->all()
            ),
            'masks' => $this->masks(),
            'operators' => $this->operators(),
            'actions' => $this->conditionActions(),
            'conditionLimits' => [
                'maxLevels' => ConditionNormalizer::MAX_LEVELS,
                'maxEntries' => ConditionNormalizer::MAX_ENTRIES,
                'maxRelativeDays' => ConditionNormalizer::MAX_RELATIVE_DAYS,
                'maxActions' => ConditionNormalizer::MAX_ACTIONS,
            ],
            'widths' => $this->options(self::WIDTHS, 'value'),
            'autocomplete' => self::AUTOCOMPLETE,
            'autocompleteLabels' => $this->autocompleteLabels(),
            'personalAutocomplete' => PersonalData::PERSONAL_AUTOCOMPLETE,
            'prefillSources' => $this->prefillSources(),
            'customerGroups' => $this->customerGroups(),
            'stores' => $this->stores(),
            'translationStores' => $this->translationStores(),
            'storeCodes' => $this->storeCodes(),
            'phrases' => $this->phrases->all(),
            'uploads' => [
                'allowedExtensions' => $this->moduleConfig->getAllowedExtensions(),
                'maxMb' => $this->moduleConfig->getMaxUploadMb(),
            ],
        ];
    }

    /** @return array<string, string> */
    private function autocompleteLabels(): array
    {
        return [
            'name' => (string)__('Full name'),
            'given-name' => (string)__('First name'),
            'family-name' => (string)__('Last name'),
            'email' => (string)__('Email'),
            'tel' => (string)__('Phone number'),
            'organization' => (string)__('Company'),
            'street-address' => (string)__('Street address (all lines)'),
            'address-line1' => (string)__('Address line 1'),
            'address-line2' => (string)__('Address line 2'),
            'address-level1' => (string)__('State or region'),
            'address-level2' => (string)__('City'),
            'postal-code' => (string)__('Postcode'),
            'country' => (string)__('Country code'),
            'country-name' => (string)__('Country name'),
            'bday' => (string)__('Date of birth'),
            'url' => (string)__('Website'),
            'off' => (string)__('Off (no browser suggestions)'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function prefillSources(): array
    {
        $out = [];
        foreach ($this->sources->all() as $source) {
            $out[] = [
                'code' => $source->getCode(),
                'label' => (string)$source->getLabel(),
                'group' => $source->getGroup(),
                'types' => array_values(array_filter(
                    array_keys($this->fieldTypePool->all()),
                    $source->suitsType(...)
                )),
            ];
        }
        return $out;
    }

    /**
     * The source prepends ALL GROUPS (32000), which matches no real group, so it is left out.
     *
     * @return list<array{value: int, label: string}>
     */
    private function customerGroups(): array
    {
        $groups = [];
        foreach ($this->groupSource->toOptionArray() as $group) {
            if ((int)$group['value'] === GroupInterface::CUST_GROUP_ALL) {
                continue;
            }
            $groups[] = ['value' => (int)$group['value'], 'label' => (string)$group['label']];
        }
        return $groups;
    }

    /** @return list<array<string, mixed>> */
    private function fieldTypes(): array
    {
        $types = [];
        foreach ($this->fieldTypePool->all() as $code => $type) {
            if (!$this->fieldTypePool->isUsable((string)$code)) {
                continue;
            }
            $types[] = [
                'code' => $type->getCode(),
                'label' => (string)$type->getLabel(),
                'category' => $type->getCategory(),
                'allowedRules' => $type->getAllowedRules(),
                'settingsSpec' => $this->settingLabels->apply($type->getSettingsSpec()),
                'hasOptions' => $type->hasOptions(),
                'isFile' => $type->isFile(),
                'isMultiValue' => $type->isMultiValue(),
                'emailAddress' => $type instanceof EmailAddressTypeInterface,
                'defaults' => $type->getBuilderDefaults(),
                'relativeDate' => $type instanceof RelativeDateSourceInterface ? $type->relativeDateFormat() : null,
                'routable' => !$type->isFile() && !($type instanceof RoutableTypeInterface && !$type->canBeRouted()),
                'personalByDefault' => $type instanceof PersonalByDefaultInterface && $type->isPersonalByDefault(),
                'conditionSource' => !($type instanceof ConditionSourceInterface) || $type->canBeConditionSource(),
                'controls' => $this->controls($type),
                'presets' => $this->presets($type),
                'maxLength' => $this->fieldTypePool->maxLength((string)$code),
            ] + $this->serverFacts((string)$code, $type) + $this->defaultChoices($type);
        }
        return $types;
    }

    /**
     * A list keeps the type's order in the browser, where numeric object keys would sort first.
     *
     * @param array<string, string> $choices
     * @return list<array{value: string, label: string}>
     */
    private function choiceList(array $choices): array
    {
        $list = [];
        foreach ($choices as $value => $label) {
            $list[] = ['value' => (string)$value, 'label' => (string)$label];
        }
        return $list;
    }

    /**
     * The type's display choices plus the Default input kind (`default`), which is absent when the type has no Default.
     *
     * @return array<string, bool|string>
     */
    private function controls(FieldTypeInterface $type): array
    {
        $controls = $type instanceof BuilderControlsInterface ? $type->builderControls() : [];
        // The Default input comes only from DefaultValueInterface, whatever a type returns here.
        unset($controls['default']);
        $input = $this->defaultInput($type);
        return $input === null ? $controls : $controls + ['default' => $input];
    }

    private function defaultInput(FieldTypeInterface $type): ?string
    {
        return $type instanceof DefaultValueInterface ? $type->defaultInput() : DefaultValueInterface::INPUT_TEXT;
    }

    /**
     * @return array{defaultChoices?: list<array{value: string, label: string}>}
     */
    private function defaultChoices(FieldTypeInterface $type): array
    {
        $choices = $type instanceof DefaultValueInterface ? $type->defaultChoices() : null;
        return $choices === null ? [] : ['defaultChoices' => $this->choiceList($choices)];
    }

    /**
     * What the Normalizer enforces for the type, so the builder hides controls whose value the server would refuse.
     *
     * @return array{consent: bool, serverValue: bool, requiredAllowed: bool, draftAllowed: bool, defaultAllowed: bool}
     */
    private function serverFacts(string $code, FieldTypeInterface $type): array
    {
        $serverValue = $this->fieldTypePool->isServerValue($code);
        $consent = $this->fieldTypePool->isConsent($code);
        return [
            'consent' => $consent,
            'serverValue' => $serverValue,
            'requiredAllowed' => !$serverValue && !$consent,
            'draftAllowed' => !$serverValue && !$consent && !$type->isFile(),
            'defaultAllowed' => !$consent && $this->defaultInput($type) !== null,
        ];
    }

    /**
     * Library references the type's `preset` rule accepts.
     *
     * @return list<string>
     */
    private function presets(FieldTypeInterface $type): array
    {
        if (!in_array('preset', $type->getAllowedRules(), true)) {
            return [];
        }
        $refs = [];
        foreach ($this->ruleLibrary->all() as $entry) {
            if ($this->presetFit->fitsType($entry['group_code'], $type)) {
                $refs[] = $entry['ref'];
            }
        }
        return $refs;
    }

    /**
     * `types` lists the usable field types the action fits, mirroring what ConditionNormalizer accepts.
     *
     * @return list<array{code: string, label: string, targets: list<string>, param: ?string, types: list<string>}>
     */
    private function conditionActions(): array
    {
        $actions = [];
        foreach ($this->actionPool->all() as $action) {
            $actions[] = [
                'code' => $action->getCode(),
                'label' => (string)$action->getLabel(),
                'targets' => array_values($action->targets()),
                'param' => $action->needsParam(),
                'types' => $this->typesFor($action),
            ];
        }
        return $actions;
    }

    /**
     * @return list<string>
     */
    private function typesFor(ConditionActionInterface $action): array
    {
        $codes = [];
        foreach ($this->fieldTypePool->all() as $code => $type) {
            $param = $action->needsParam();
            $fits = $this->fieldTypePool->isUsable((string)$code)
                && !($param === ConditionActionInterface::PARAM_VALUE && ($type->isFile() || $type->isMultiValue()))
                && !($param === ConditionActionInterface::PARAM_OPTIONS && !$type->hasOptions())
                && ConditionNormalizer::actionFits($action, $type);
            if ($fits) {
                $codes[] = (string)$code;
            }
        }
        return $codes;
    }

    /**
     * Mask presets are the library formats the mask engine knows, labelled like the format picker.
     *
     * @return array<int, array{code: string, label: string}>
     */
    private function masks(): array
    {
        $masks = [];
        foreach ($this->ruleLibrary->all() as $entry) {
            if (Mask::isPreset($entry['ref'])) {
                $masks[] = ['code' => $entry['ref'], 'label' => $entry['label']];
            }
        }
        return $masks;
    }

    /**
     * `types` lists the registered field types the operator applies to; the builder offers it only for those.
     *
     * @return list<array<string, mixed>>
     */
    private function operators(): array
    {
        $operators = [];
        foreach ($this->operatorPool->all() as $operator) {
            $operators[] = [
                'code' => $operator->getCode(),
                'label' => (string)$operator->getLabel(),
                'needsValue' => $operator->needsValue(),
                'types' => array_values(array_filter(
                    array_keys($this->fieldTypePool->all()),
                    $operator->appliesTo(...)
                )),
            ];
        }
        return $operators;
    }

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        $rules = [];
        foreach ($this->rulePool->all() as $rule) {
            $rules[] = [
                'code' => $rule->getCode(),
                'label' => (string)$rule->getLabel(),
                'valueType' => $rule->getValueType(),
            ];
        }
        return $rules;
    }

    /**
     * @param array<string, string> $labels
     * @return list<array<string, string>>
     */
    private function options(array $labels, string $valueKey): array
    {
        $options = [];
        foreach ($labels as $code => $label) {
            $options[] = [$valueKey => $code, 'label' => (string)__($label)];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function stores(): array
    {
        $stores = [];
        $website = '';
        foreach ($this->systemStore->getStoreValuesForForm(false, true) as $option) {
            $label = $this->cleanLabel($option['label']);
            if (!is_array($option['value'])) {
                $stores[] = ['value' => (int)$option['value'], 'label' => $label];
            } elseif ($option['value'] === []) {
                $website = $label;
            } else {
                foreach ($option['value'] as $store) {
                    $stores[] = [
                        'value' => (int)$store['value'],
                        'label' => implode(' / ', [$website, $label, $this->cleanLabel($store['label'])]),
                    ];
                }
            }
        }
        return $stores;
    }

    /**
     * Every active store view; the builder narrows this to the form's assigned views.
     *
     * @return list<array<string, mixed>>
     */
    private function translationStores(): array
    {
        $stores = [];
        /** @var \Magento\Store\Model\Store $store */
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $stores[] = [
                'id' => (int)$store->getId(),
                'code' => (string)$store->getCode(),
                'name' => (string)$store->getName(),
                'locale' => (string)$this->scopeConfig->getValue(
                    'general/locale/code',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ),
            ];
        }
        return $stores;
    }

    /**
     * Every store view, active or not, for the definition export; store 0 is the builder's `admin`.
     *
     * @return array<int, string>
     */
    private function storeCodes(): array
    {
        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $codes[(int)$store->getId()] = (string)$store->getCode();
        }
        return $codes;
    }

    private function cleanLabel(mixed $label): string
    {
        return trim((string)preg_replace('/^[\s\x{00A0}]+/u', '', (string)$label));
    }
}
