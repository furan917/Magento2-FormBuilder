<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Admin;

use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
use Furan\FormBuilder\Block\Adminhtml\Form\BuilderPhrases;
use Furan\FormBuilder\Model\Admin\BuilderSharedConfig;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Country;
use Furan\FormBuilder\Model\FieldType\Email;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\SettingLabels;
use Furan\FormBuilder\Model\FieldType\Tel;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\FieldType\Textarea;
use Furan\FormBuilder\Model\Validation\RuleLibrary;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ActionPools;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools;
use Furan\FormBuilder\Test\Unit\Helper\SmsConsentType;
use Magento\Customer\Model\Customer\Source\GroupSourceInterface;
use Magento\Directory\Model\AllowedCountries;
use Magento\Framework\Locale\ListsInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\System\Store;
use PHPUnit\Framework\TestCase;

class BuilderSharedConfigTest extends TestCase
{
    private const LIBRARY = [
        ['ref' => 'uk_postcode', 'label' => 'UK postcode', 'group' => 'Postcodes', 'group_code' => 'postcode',
            'pattern' => 'x', 'message' => '', 'example' => '', 'saved' => false],
        ['ref' => 'uk_phone', 'label' => 'UK phone', 'group' => 'Phone', 'group_code' => 'phone',
            'pattern' => 'x', 'message' => '', 'example' => '', 'saved' => false],
        ['ref' => 'saved:crm', 'label' => 'CRM id', 'group' => 'Saved rules', 'group_code' => '',
            'pattern' => 'x', 'message' => '', 'example' => '', 'saved' => true],
    ];

    /** @var array<string, mixed> */
    private array $shared = [];

    /** @return array<string, array<string, mixed>> */
    private function types(): array
    {
        $library = $this->createStub(RuleLibrary::class);
        $library->method('all')->willReturn(self::LIBRARY);
        $lists = $this->createStub(ListsInterface::class);
        $lists->method('getOptionCountries')->willReturn([
            ['value' => '150', 'label' => 'Europe'],
            ['value' => 'FR', 'label' => 'France'],
            ['value' => 'GB', 'label' => 'United Kingdom'],
        ]);
        $groups = $this->createStub(GroupSourceInterface::class);
        $groups->method('toOptionArray')->willReturn([]);
        $store = $this->createStub(Store::class);
        $store->method('getStoreValuesForForm')->willReturn([]);
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([]);
        $config = new BuilderSharedConfig(
            new Pool([
                'text' => new Text(), 'email' => new Email(), 'tel' => new Tel(), 'textarea' => new Textarea(),
                'file' => new File(), 'hidden' => new Hidden(), 'select' => new Select(),
                SmsConsentType::CODE => new SmsConsentType(),
                'country' => new Country($this->createStub(AllowedCountries::class), $lists, $this->locale('en_US')),
                'acme' => new class extends Text implements BuilderControlsInterface {
                    public function getCode(): string
                    {
                        return 'acme';
                    }

                    public function builderControls(): array
                    {
                        return ['placeholder' => false, 'default' => 'date'];
                    }
                },
            ]),
            new RulePool(),
            $library,
            OperatorPools::builtIn(),
            ActionPools::builtIn(),
            new SettingLabels(),
            PrefillSourcePools::builtIn(),
            $groups,
            $store,
            $manager,
            $this->createStub(ScopeConfigInterface::class),
            new BuilderPhrases(),
            $this->createStub(Config::class),
            new \Furan\FormBuilder\Model\Validation\PresetFit()
        );
        $this->shared = $config->get();
        $out = [];
        foreach ($this->shared['fieldTypes'] as $type) {
            $out[$type['code']] = $type;
        }
        return $out;
    }

    public function testItSendsTheServerFactsBesideTheDisplayChoices(): void
    {
        $types = $this->types();
        $facts = static fn (string $code): array => array_intersect_key(
            $types[$code],
            array_flip(['consent', 'serverValue', 'requiredAllowed', 'draftAllowed', 'defaultAllowed'])
        );
        $this->assertSame(
            ['consent' => false, 'serverValue' => true, 'requiredAllowed' => false, 'draftAllowed' => false, 'defaultAllowed' => true],
            $facts('hidden')
        );
        $this->assertSame(
            ['consent' => true, 'serverValue' => false, 'requiredAllowed' => false, 'draftAllowed' => false, 'defaultAllowed' => false],
            $facts(SmsConsentType::CODE)
        );
        $this->assertSame(
            ['consent' => false, 'serverValue' => false, 'requiredAllowed' => true, 'draftAllowed' => false, 'defaultAllowed' => false],
            $facts('file')
        );
        $this->assertSame(
            ['consent' => false, 'serverValue' => false, 'requiredAllowed' => true, 'draftAllowed' => true, 'defaultAllowed' => true],
            $facts('text')
        );
        $this->assertSame(['default' => 'text'], $types['text']['controls']);
        foreach (['hidden', SmsConsentType::CODE, 'file'] as $code) {
            $this->assertSame([], array_intersect_key($types[$code]['controls'], array_flip(['required', 'draft'])), $code);
            $this->assertNotFalse($types[$code]['controls']['default'] ?? null, $code);
        }
        $this->assertArrayNotHasKey('default', $types['file']['controls']);
    }

    public function testItKeepsTheTypesOwnControls(): void
    {
        $types = $this->types();
        $this->assertSame('option', $types['select']['controls']['default']);
        $this->assertFalse($types['select']['controls']['placeholder']);
    }

    public function testItListsTheLibraryFormatsEachTypeAccepts(): void
    {
        $types = $this->types();
        $this->assertSame(['uk_postcode', 'uk_phone', 'saved:crm'], $types['text']['presets']);
        $this->assertSame(['uk_phone', 'saved:crm'], $types['tel']['presets']);
        $this->assertSame(['saved:crm'], $types['email']['presets']);
        $this->assertSame(['saved:crm'], $types['textarea']['presets']);
        $this->assertSame([], $types['select']['presets']);
    }

    public function testItLabelsEveryAutocompleteToken(): void
    {
        $this->types();
        foreach ($this->shared['autocomplete'] as $token) {
            $this->assertTrue($token === '' || isset($this->shared['autocompleteLabels'][$token]), $token);
        }
        $this->assertSame('First name', $this->shared['autocompleteLabels']['given-name']);
    }

    public function testItTakesTheDefaultInputFromDefaultValueInterfaceNotFromTheDisplayChoices(): void
    {
        $types = $this->types();
        $this->assertSame(['placeholder' => false, 'default' => 'text'], $types['acme']['controls']);
        $this->assertTrue($types['acme']['defaultAllowed']);
    }

    public function testItSendsTheLongestAnswerEachTypeKeeps(): void
    {
        $types = $this->types();
        $this->assertSame(1000, $types['text']['maxLength']);
        $this->assertSame(255, $types['hidden']['maxLength']);
    }

    public function testItSendsTheDefaultChoicesOfATypeWhoseOptionsTheFieldDoesNotStore(): void
    {
        $types = $this->types();
        $this->assertSame(
            [['value' => 'FR', 'label' => 'France'], ['value' => 'GB', 'label' => 'United Kingdom']],
            $types['country']['defaultChoices']
        );
        $this->assertArrayNotHasKey('defaultChoices', $types['text']);
    }

    public function testItOffersOnlyCountriesNotRegionsAsCountryDefaults(): void
    {
        $lists = $this->createStub(ListsInterface::class);
        $lists->method('getOptionCountries')->willReturn([
            ['value' => '142', 'label' => 'Asia'],
            ['value' => 'FR', 'label' => 'France'],
        ]);
        $country = new Country($this->createStub(AllowedCountries::class), $lists, $this->locale('en_US'));
        $this->assertSame(['FR' => 'France'], $country->defaultChoices());
        $this->assertFalse($country->isValidDefault('142', []));
        $this->assertTrue($country->isValidDefault('FR', []));
    }

    public function testItSortsCountryDefaultsByLabelForTheLocale(): void
    {
        $lists = $this->createStub(ListsInterface::class);
        $lists->method('getOptionCountries')->willReturn([
            ['value' => 'AF', 'label' => 'Afghanistan'],
            ['value' => 'AL', 'label' => 'Albania'],
            ['value' => 'ZW', 'label' => 'Zimbabwe'],
            ['value' => 'AX', 'label' => 'Åland Islands'],
        ]);
        $country = new Country($this->createStub(AllowedCountries::class), $lists, $this->locale('en_US'));
        $this->assertSame(['AF', 'AX', 'AL', 'ZW'], array_keys($country->defaultChoices()));
    }

    private function locale(string $code): ResolverInterface
    {
        $resolver = $this->createStub(ResolverInterface::class);
        $resolver->method('getLocale')->willReturn($code);
        return $resolver;
    }
}
