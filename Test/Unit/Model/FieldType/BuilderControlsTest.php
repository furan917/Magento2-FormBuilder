<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
use Furan\FormBuilder\Api\FieldType\DefaultValueInterface;
use Furan\FormBuilder\Api\FieldType\PresetGroupsInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;
use Furan\FormBuilder\Model\FieldType\AbstractOptionType;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\TestCase;

class BuilderControlsTest extends TestCase
{
    /** @return array<string, array<string, bool|string>> */
    private function controls(): array
    {
        $out = [];
        foreach (FieldPools::builtIn()->all() as $code => $type) {
            $out[$code] = $type instanceof BuilderControlsInterface ? $type->builderControls() : [];
        }
        return $out;
    }

    /** @param array<string, array<string, bool|string>> $controls */
    private function showing(array $controls, string $key): array
    {
        $codes = array_keys(array_filter($controls, static fn (array $c): bool => ($c[$key] ?? true) !== false));
        sort($codes);
        return $codes;
    }

    public function testItOffersAPlaceholderOnlyWhereTheStorefrontRendersOne(): void
    {
        $this->assertSame(
            ['email', 'list', 'number', 'suggest', 'tel', 'text', 'textarea', 'url'],
            array_values(array_diff($this->showing($this->controls(), 'placeholder'), ['color']))
        );
    }

    public function testItOffersAutocompleteOnlyWhereTheStorefrontRendersIt(): void
    {
        $this->assertSame(
            ['country', 'date', 'datetime', 'email', 'month', 'number', 'select', 'suggest', 'tel', 'text',
                'textarea', 'time', 'url'],
            array_values(array_diff($this->showing($this->controls(), 'autocomplete'), ['color']))
        );
    }

    public function testTheDefaultInputComesFromTheDefaultValueCapabilityNotTheBuilderControls(): void
    {
        $pool = FieldPools::builtIn();
        foreach ($this->controls() as $code => $own) {
            $this->assertArrayNotHasKey('default', $own, $code);
        }
        $input = static fn (string $code): ?string => ($t = $pool->get($code)) instanceof DefaultValueInterface ? $t->defaultInput() : 'text';
        $kinds = ['select' => 'option', 'radio' => 'option', 'cards' => 'option', 'checkboxes' => 'option', 'multiselect' => 'option',
            'country' => 'option', 'date' => 'date', 'datetime' => 'datetime-local', 'month' => 'month', 'time' => 'time',
            'number' => 'number', 'rating' => 'number', 'scale' => 'number', 'range' => 'number', 'text' => 'text',
            'textarea' => 'text', 'email' => 'text'];
        foreach ($kinds as $code => $kind) {
            $this->assertSame($kind, $input($code), $code);
        }
    }

    public function testItOffersOptionDescriptionsOnlyForCardsAndCallsMatrixOptionsColumns(): void
    {
        $controls = $this->controls();
        $describing = array_keys(array_filter(
            $controls,
            static fn (array $c, string $code): bool => FieldPools::builtIn()->get($code)->hasOptions()
                && ($c['option_description'] ?? true) !== false,
            ARRAY_FILTER_USE_BOTH
        ));
        $this->assertSame(['cards'], array_values($describing));
        $this->assertSame('Columns', $controls['matrix']['options_title'] ?? null);
        $this->assertSame('Add column', $controls['matrix']['option_add_label'] ?? null);
    }

    public function testTheBaseClassesKeepEveryControlForThirdPartyTypes(): void
    {
        $this->assertFalse(is_subclass_of(AbstractFieldType::class, BuilderControlsInterface::class));
        $this->assertFalse(is_subclass_of(AbstractOptionType::class, BuilderControlsInterface::class));
    }

    public function testItLimitsLibraryFormatsByType(): void
    {
        $pool = FieldPools::builtIn();
        $groups = static fn (string $code): ?array => ($t = $pool->get($code)) instanceof PresetGroupsInterface ? $t->presetGroups() : null;
        $this->assertSame([], $groups('email'));
        $this->assertSame(['phone'], $groups('tel'));
        $this->assertSame(['content'], $groups('textarea'));
        $this->assertNull($groups('text'));
    }
}
