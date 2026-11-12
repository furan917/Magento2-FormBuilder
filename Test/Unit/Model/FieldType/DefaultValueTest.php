<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\DefaultValueInterface;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\TestCase;

class DefaultValueTest extends TestCase
{
    public function testEachBuiltInTypeStatesHowTheBuilderAsksForItsDefault(): void
    {
        $inputs = [];
        foreach (FieldPools::builtIn()->all() as $code => $type) {
            $this->assertInstanceOf(DefaultValueInterface::class, $type, $code);
            $inputs[$code] = $type->defaultInput();
        }
        ksort($inputs);

        $this->assertSame([
            'address' => null, 'cards' => 'option', 'checkbox' => null, 'checkboxes' => 'option', 'color' => 'color',
            'consent' => null, 'country' => 'option', 'date' => 'date', 'date_range' => null, 'datetime' => 'datetime-local',
            'email' => 'text', 'file' => null, 'hidden' => 'text', 'list' => null, 'map' => null, 'matrix' => null,
            'month' => 'month', 'multiselect' => 'option', 'newsletter' => null, 'number' => 'number', 'radio' => 'option',
            'range' => 'number', 'rating' => 'number', 'scale' => 'number', 'select' => 'option', 'suggest' => 'text',
            'tel' => 'text', 'text' => 'text', 'textarea' => 'text', 'time' => 'time', 'toggle' => null, 'url' => 'text',
        ], $inputs);
    }

    public function testOnlyCountryListsDefaultChoicesOfItsOwn(): void
    {
        foreach (FieldPools::builtIn()->all() as $code => $type) {
            if ($code !== 'country') {
                $this->assertNull($type->defaultChoices(), $code);
            }
        }
    }
}
