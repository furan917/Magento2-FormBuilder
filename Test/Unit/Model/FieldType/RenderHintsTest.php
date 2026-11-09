<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Block\ItemContext;
use Furan\FormBuilder\Model\Directory\AddressRules;
use Furan\FormBuilder\Model\FieldType\Address;
use Furan\FormBuilder\Model\FieldType\Rating;
use Furan\FormBuilder\Model\FieldType\Scale;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory;
use PHPUnit\Framework\TestCase;

class RenderHintsTest extends TestCase
{
    public function testItKeepsTheInlineLayoutOfRatingAndScaleThroughRenderHints(): void
    {
        $field = ['name' => 'r', 'type' => 'rating', 'settings' => []];
        $escaper = EscaperFactory::create();

        $this->assertSame(['layout' => 'inline'], (new Rating())->renderHints($field, 1));
        $this->assertSame(['layout' => 'inline'], (new Scale())->renderHints($field, 1));
        $this->assertSame('inline', ItemContext::build($field + ['render' => ['layout' => 'inline']], 7, 1, $escaper)['layout']);
        $this->assertSame('stacked', ItemContext::build($field, 7, 1, $escaper)['layout']);
        $inline = ['settings' => ['layout' => 'inline']] + $field;
        $this->assertSame('inline', ItemContext::build($inline, 7, 1, $escaper)['layout']);
    }

    public function testItReadsTheAddressDirectoryOncePerStoreForHintsAndConfig(): void
    {
        $rules = $this->createMock(AddressRules::class);
        $rules->expects($this->exactly(2))->method('getDirectoryData')->willReturnCallback(static fn (int $store): array => [
            'streetLines' => $store + 1, 'allowedCountries' => [], 'regionsRequired' => [], 'optionalZip' => [],
            'postcodes' => [], 'regions' => [],
        ]);
        $address = new Address($rules, StorefrontUrlsFactory::create());

        $this->assertSame(['street_lines' => 2], $address->renderHints([], 1));
        $this->assertSame(2, $address->storefrontConfig([], 1)['directory']['streetLines']);
        $this->assertSame(['street_lines' => 2], $address->renderHints([], 1));
        $this->assertSame(['street_lines' => 3], $address->renderHints([], 2));
    }
}
