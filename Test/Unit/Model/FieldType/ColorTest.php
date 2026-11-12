<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Color;
use PHPUnit\Framework\TestCase;

class ColorTest extends TestCase
{
    public function testItTreatsAMissingOrEmptyColourAsUnansweredAndChecksAGivenOne(): void
    {
        $color = new Color();
        $this->assertNull($color->normalizeValue(null, []));
        $this->assertSame('', $color->normalizeValue('', []));
        $this->assertSame('#a1b2c3', $color->normalizeValue('#A1B2C3', []));
        $this->assertNull($color->validateValue('#a1b2c3', [], []));
        foreach (['#abc', 'red', '#a1b2c3x', 'javascript:alert(1)'] as $bad) {
            $this->assertSame('Please choose a valid colour.', $color->validateValue((string)$color->normalizeValue($bad, []), [], []), $bad);
        }
        $this->assertNull($color->normalizeValue(['#a1b2c3'], []));
    }

    public function testItHidesPlaceholderAndAutocompleteAndOffersAColourDefaultInTheBuilder(): void
    {
        $this->assertSame(['placeholder' => false, 'autocomplete' => false], (new Color())->builderControls());
        $this->assertSame('color', (new Color())->defaultInput());
    }
}
