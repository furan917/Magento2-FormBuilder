<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Model\FieldType\Email;
use Furan\FormBuilder\Model\FieldType\Tel;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Validation\PresetFit;
use PHPUnit\Framework\TestCase;

class PresetFitTest extends TestCase
{
    public function testItFitsSavedRulesEverywhereAndGroupsByTheTypesChoice(): void
    {
        $fit = new PresetFit();
        $this->assertTrue($fit->fitsType('postcode', new Text()));
        $this->assertFalse($fit->fitsType('postcode', new Tel()));
        $this->assertTrue($fit->fitsType('phone', new Tel()));
        $this->assertTrue($fit->fitsType('', new Email()));
        $this->assertFalse($fit->fitsType('phone', new Email()));
    }
}
