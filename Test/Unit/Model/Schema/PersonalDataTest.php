<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PersonalDataTest extends TestCase
{
    #[DataProvider('onTypes')]
    public function testItDefaultsThePersonalDataFlagOnForEmailPhoneAddressMapAndFileFields(string $type): void
    {
        $this->assertTrue((new PersonalData(FieldPools::builtIn()))->isPersonal(['type' => $type]));
    }

    public static function onTypes(): array
    {
        return [['email'], ['tel'], ['address'], ['map'], ['file']];
    }

    #[DataProvider('offTypes')]
    public function testItDefaultsThePersonalDataFlagOffForChoiceFields(string $type): void
    {
        $this->assertFalse((new PersonalData(FieldPools::builtIn()))->isPersonal(['type' => $type, 'autocomplete' => '']));
    }

    public static function offTypes(): array
    {
        return [['select'], ['radio'], ['checkboxes'], ['country'], ['date_range'], ['text'], ['rating']];
    }

    public function testItResolvesThePersonalDataDefaultForAStoredFieldWithoutTheFlag(): void
    {
        $data = new PersonalData(FieldPools::builtIn());
        $this->assertTrue($data->isPersonal(['type' => 'email', 'name' => 'e']));
        $this->assertFalse($data->isPersonal(['type' => 'select', 'name' => 's']));
        $this->assertFalse($data->isPersonal(['type' => 'email', 'personal' => false]));
        $this->assertTrue($data->isPersonal(['type' => 'select', 'personal' => true]));
        $this->assertTrue($data->isPersonal(['type' => 'select', 'personal' => 'yes']) === false);
    }

    #[DataProvider('tokens')]
    public function testItDefaultsThePersonalDataFlagOnForTextFieldsWithAPersonalAutocompleteToken(
        string $token,
        bool $expected
    ): void {
        $this->assertSame($expected, (new PersonalData(FieldPools::builtIn()))->isPersonal(['type' => 'text', 'autocomplete' => $token]));
    }

    public static function tokens(): array
    {
        return [
            ['name', true], ['given-name', true], ['family-name', true], ['email', true], ['tel', true],
            ['street-address', true], ['address-line1', true], ['address-line2', true], ['postal-code', true],
            ['bday', true], ['country', false], ['country-name', false], ['address-level1', false],
            ['address-level2', false], ['organization', false], ['url', false], ['off', false], ['', false],
        ];
    }

    public function testItDecidesThePersonalDataDefaultFromPersonalByDefaultInterface(): void
    {
        $personal = new class extends AbstractFieldType implements PersonalByDefaultInterface {
            public function getCode(): string
            {
                return 'passport';
            }

            public function getLabel(): string
            {
                return 'Passport';
            }

            public function isPersonalByDefault(): bool
            {
                return true;
            }
        };
        $data = new PersonalData(new Pool(['passport' => $personal, 'plain' => FieldPools::structured()->get('text')]));

        $this->assertTrue($data->isPersonal(['type' => 'passport']));
        $this->assertFalse($data->isPersonal(['type' => 'passport', 'personal' => false]));
        $this->assertFalse($data->isPersonal(['type' => 'plain']));
        $this->assertFalse($data->isPersonal(['type' => 'gone_module']), 'An unregistered type has no default');
    }

    public function testItKeepsIsPersonalUnchangedForPrivacyExport(): void
    {
        $data = new PersonalData(FieldPools::builtIn());

        $this->assertFalse($data->isPersonal(['type' => 'email', 'personal' => false]));
        $this->assertFalse($data->isPersonal(['type' => 'text', 'personal' => false, 'autocomplete' => 'email']));
    }

    public function testItIgnoresAnExplicitNotPersonalFlagWhenSharing(): void
    {
        $data = new PersonalData(FieldPools::builtIn());

        $this->assertTrue($data->isPersonalForSharing(['type' => 'email', 'personal' => false]));
        $this->assertTrue($data->isPersonalForSharing(['type' => 'text', 'personal' => false, 'autocomplete' => 'tel']));
        $this->assertTrue($data->isPersonalForSharing(['type' => 'text', 'personal' => false], 'email'));
        $this->assertTrue($data->isPersonalForSharing(['type' => 'text', 'personal' => true]));
        $this->assertFalse($data->isPersonalForSharing(['type' => 'text', 'personal' => false], 'text'));
    }
}
