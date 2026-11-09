<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Rule;

use Furan\FormBuilder\Block\Adminhtml\Rule\Edit;
use Furan\FormBuilder\Block\Adminhtml\Rule\Index;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class TemplatesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../../../view/adminhtml/templates/rule/';

    public function testWithoutSaveRightsTheRulePageIsReadOnly(): void
    {
        $html = $this->renderEdit(false);
        $this->assertStringNotContainsString('Save rule', $html);
        $this->assertStringNotContainsString('data-ffb-rule-delete', $html);
        $this->assertStringContainsString('You can view this rule but not change it.', $html);
        $this->assertSame(4, substr_count($html, ' readonly>'));

        $html = $this->renderEdit(true);
        $this->assertStringContainsString('Save rule', $html);
        $this->assertStringContainsString('data-ffb-rule-delete', $html);
        $this->assertStringNotContainsString(' readonly>', str_replace('id="ffb-rule-code" type="text" readonly', '', $html));
    }

    public function testWithoutSaveRightsTheRuleListHasNoAddOrCopyLinks(): void
    {
        foreach ([true, false] as $canSave) {
            $block = $this->createStub(Index::class);
            $block->method('canSave')->willReturn($canSave);
            $block->method('getFilters')->willReturn(['q' => '', 'usage' => 'all', 'sort' => 'name', 'limit' => 20, 'page' => 1]);
            $block->method('getListing')->willReturn(['rows' => [], 'total' => 0, 'all' => 0, 'page' => 1, 'pages' => 1, 'from' => 0, 'to' => 0]);
            $block->method('getBuiltInGroups')->willReturn(['Postcodes' => [
                ['label' => 'UK postcode', 'example' => 'SW1A 1AA', 'pattern' => '[A-Z]+', 'copy_url' => '/copy'],
            ]]);
            $block->method('getNewUrl')->willReturn('/new');
            $html = $this->render($block, 'index.phtml');
            $this->assertSame($canSave, str_contains($html, 'href="/new"'));
            $this->assertSame($canSave, str_contains($html, 'Copy and edit'));
        }
    }

    private function renderEdit(bool $canSave): string
    {
        $block = $this->createStub(Edit::class);
        $block->method('canSave')->willReturn($canSave);
        $block->method('isNew')->willReturn(false);
        $block->method('getRuleId')->willReturn(3);
        $block->method('getValues')->willReturn(['label' => 'Ref', 'code' => 'ref', 'pattern' => '[0-9]+', 'message' => 'm', 'example' => '1']);
        $block->method('getUsage')->willReturn([]);
        return $this->render($block, 'edit.phtml');
    }

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

    public function testTheRuleListShowsSearchPagingAndUsagePills(): void
    {
        $block = $this->createStub(Index::class);
        $block->method('canSave')->willReturn(true);
        $block->method('getFilters')->willReturn(['q' => 'code "x"', 'usage' => 'used', 'sort' => 'usage', 'limit' => 20, 'page' => 2]);
        $block->method('hasActiveFilters')->willReturn(true);
        $row = static fn (int $used): array => ['id' => 1, 'code' => 'c', 'label' => '<b>L</b>', 'pattern' => 'p', 'example' => 'e',
            'updated_at' => '', 'used' => $used, 'url' => '/edit'];
        $block->method('getListing')->willReturn(['rows' => [$row(1), $row(3), $row(0)], 'total' => 45, 'all' => 60,
            'page' => 2, 'pages' => 3, 'from' => 21, 'to' => 40]);
        $block->method('getListUrl')->willReturnCallback(static fn (array $c): string => '/list?p=' . $c['p']);
        $block->method('getClearUrl')->willReturn('/clear');
        $block->method('getBuiltInGroups')->willReturn([]);
        $html = $this->render($block, 'index.phtml');

        $this->assertStringContainsString('value="code&#x20;&quot;x&quot;"', $html);
        $this->assertStringNotContainsString('<b>L</b>', $html);
        $this->assertStringContainsString('>1 form<', $html);
        $this->assertStringContainsString('>3 forms<', $html);
        $this->assertStringContainsString('Not used yet', $html);
        $this->assertStringContainsString('Showing 21 to 40 of 45', $html);
        $this->assertStringContainsString('href="/list?p=1"', $html);
        $this->assertStringContainsString('href="/list?p=3"', $html);
        $this->assertStringContainsString('aria-current="page">2<', $html);
        $this->assertStringContainsString('href="/clear"', $html);
    }
}
