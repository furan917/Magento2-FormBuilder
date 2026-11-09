<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\FieldGroup;

use Furan\FormBuilder\Block\Adminhtml\FieldGroup\Edit;
use Furan\FormBuilder\Block\Adminhtml\FieldGroup\Index;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\ViewModel\FieldGroupEditor;
use PHPUnit\Framework\TestCase;

class TemplatesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../../../view/adminhtml/templates/field_group/';

    private function render(object $block, string $template): string
    {
        $escaper = EscaperFactory::create();
        $renderer = static function () use ($block, $escaper, $template): string {
            ob_start();
            include self::DIR . $template;
            return (string)ob_get_clean();
        };
        return $renderer();
    }

    private function edit(): string
    {
        $editor = $this->createStub(FieldGroupEditor::class);
        $editor->method('getGroupId')->willReturn(3);
        $editor->method('getSaveUrl')->willReturn('/save');
        $block = $this->createStub(Edit::class);
        $block->method('getEditor')->willReturn($editor);
        $block->method('getFormKey')->willReturn('KEY"123');
        $block->method('getConfigJson')->willReturn('{"group":{"usage":{"rows":[],"total":0}}}');
        $block->method('getViewFileUrl')->willReturnCallback(static fn (string $file): string => '/static/' . $file);
        return $this->render($block, 'edit.phtml');
    }

    public function testTheGroupPageMountsTheBuilderWithTheGroupsSaveInputs(): void
    {
        $html = $this->edit();

        $this->assertStringContainsString('<form id="ffb-admin-form" class="ffb-admin" method="post" action="/save">', $html);
        foreach (['name', 'description', 'fields', 'translations', 'back'] as $input) {
            $this->assertStringContainsString('name="' . $input . '" value=""', $html);
        }
        $this->assertStringContainsString('name="id" value="3"', $html);
        $this->assertStringContainsString('name="form_key" value="KEY&quot;123"', $html);
        $this->assertStringContainsString('data-ffb-builder', $html);
        $this->assertStringContainsString('<script type="application/json" id="ffb-builder-config">{"group":', $html);
        $this->assertStringContainsString('/static/Furan_FormBuilder::js/builder.js', $html);
        $this->assertSame(1, substr_count($html, '<form '), 'delete is a builder toolbar action');
    }

    public function testTheGroupListEscapesNamesAndPagesWithACursor(): void
    {
        $block = $this->createStub(Index::class);
        $block->method('getListing')->willReturn(['rows' => [
            ['id' => 1, 'name' => '<b>Address</b>', 'description' => 'd', 'updated_at' => '', 'url' => '/edit/1', 'forms' => 1],
            ['id' => 2, 'name' => 'Contact', 'description' => '', 'updated_at' => '', 'url' => '/edit/2', 'forms' => 4],
        ], 'next' => 1]);
        $block->method('getNextUrl')->willReturn('/list?after=1');
        $block->method('getFirstUrl')->willReturn('/list');
        $block->method('isFirstPage')->willReturn(false);
        $html = $this->render($block, 'index.phtml');

        $this->assertStringNotContainsString('<b>Address</b>', $html);
        $this->assertStringContainsString('href="/edit/1"', $html);
        $this->assertStringContainsString('href="/list?after=1"', $html);
        $this->assertStringContainsString('href="/list"', $html);
        $this->assertStringContainsString('>1 form<', $html);
        $this->assertStringContainsString('>4 forms<', $html);
    }

    public function testAnEmptyGroupListExplainsWhereGroupsComeFrom(): void
    {
        $block = $this->createStub(Index::class);
        $block->method('getListing')->willReturn(['rows' => [], 'next' => null]);
        $block->method('isFirstPage')->willReturn(true);
        $html = $this->render($block, 'index.phtml');

        $this->assertStringContainsString('No field groups yet. Use &quot;Save as group&quot; on a field or section.', $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
