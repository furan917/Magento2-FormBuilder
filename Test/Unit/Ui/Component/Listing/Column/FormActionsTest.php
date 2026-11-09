<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Ui\Component\Listing\Column;

use Furan\FormBuilder\Ui\Component\Listing\Column\FormActions;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class FormActionsTest extends TestCase
{
    private function column(array $denied): FormActions
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $path, array $params) => '/' . $path . '/' . http_build_query($params)
        );
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r) => !in_array($r, $denied, true));
        return new FormActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $urlBuilder,
            $auth,
            [],
            ['name' => 'actions']
        );
    }

    public function testItOffersOnlyTheActionsTheAdminUserMayRun(): void
    {
        $result = $this->column([
            'Furan_FormBuilder::forms_save',
            'Furan_FormBuilder::forms_delete',
            'Furan_FormBuilder::submissions',
            'Furan_FormBuilder::analytics',
        ])->prepareDataSource(['data' => ['items' => [['form_id' => '5']]]]);

        $actions = $result['data']['items'][0]['actions'];
        $this->assertSame(['edit'], array_keys($actions));
        $this->assertSame('View', (string)$actions['edit']['label']);
    }

    public function testItBuildsRowActionsWithPostDataForDestructiveActions(): void
    {
        $column = $this->column([]);

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['form_id' => '5', 'title' => '<b>Contact</b>'],
            ['title' => 'no id'],
        ]]]);

        $actions = $result['data']['items'][0]['actions'];
        $this->assertSame('/furanform/form/edit/id=5', $actions['edit']['href']);
        $this->assertSame('/furanform/submission/index/form_id=5', $actions['submissions']['href']);
        $this->assertSame('/furanform/analytics/index/form_id=5', $actions['analytics']['href']);
        $this->assertArrayNotHasKey('post', $actions['edit']);
        foreach (['duplicate', 'delete'] as $key) {
            $this->assertTrue($actions[$key]['post'], $key);
            $this->assertArrayHasKey('confirm', $actions[$key]);
        }
        $this->assertSame('/furanform/form/delete/id=5', $actions['delete']['href']);
        $this->assertSame('/furanform/form/duplicate/id=5', $actions['duplicate']['href']);
        $this->assertStringNotContainsString('<b>', (string)$actions['delete']['confirm']['message']);
        $this->assertArrayNotHasKey('actions', $result['data']['items'][1]);
    }
}
