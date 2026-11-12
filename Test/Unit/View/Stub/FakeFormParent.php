<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View\Stub;

use Furan\FormBuilder\Block\ItemContext;
use Furan\FormBuilder\Model\Upload\ExtensionList;
use Magento\Framework\Escaper;

/** Stands in for Block\Form when a field template is rendered on its own. */
class FakeFormParent
{
    /**
     * @param string[] $globalExtensions
     * @param array<string, int> $typeMaxLengths type code => most characters the type keeps
     */
    public function __construct(
        private readonly int $instance = 1,
        private readonly array $globalExtensions = ['pdf', 'png', 'jpg'],
        private readonly array $typeMaxLengths = []
    ) {
    }

    public function getBaseUrl(): string
    {
        return 'https://shop.test/';
    }

    public function getCountryOptions(): array
    {
        return [['value' => 'GB', 'label' => 'United Kingdom'], ['value' => 'US', 'label' => 'United States']];
    }

    public function getConfig(): array
    {
        return ['maps' => ['provider' => 'osm', 'canvasMarker' => '0123abcd0123abcd0123abcd0123abcd']];
    }

    public function getItemContext(array $item, Escaper $escaper): array
    {
        return (new ItemContext(new ExtensionList()))->build($item, 7, $this->instance, $escaper, $this->typeMaxLengths[$item['type'] ?? ''] ?? null);
    }

    public function getFileAccept(array $settings): string
    {
        return (new ItemContext(new ExtensionList()))->fileAccept($settings, $this->globalExtensions);
    }

    public function renderPartial(FakeItemBlock $block, string $partial, array $vars = []): string
    {
        return $block->assign($vars)->fetchView($block->getTemplateFile('Furan_FormBuilder::' . $partial));
    }
}
