<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Ui\Component;

use Furan\FormBuilder\Block\Adminhtml\Form\AddButton;
use Furan\FormBuilder\Ui\Component\Listing\Column\SubmissionActions;
use Furan\FormBuilder\Ui\Component\MassAction;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use PHPUnit\Framework\TestCase;

class AclGatingTest extends TestCase
{
    private function auth(array $denied): AuthorizationInterface
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r) => !in_array($r, $denied, true));
        return $auth;
    }

    public function testMassActionsTheUserMayNotRunAreLeftOut(): void
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));
        $actions = [];
        foreach (['delete', 'enable', 'disable'] as $type) {
            $actions[$type] = new class ($type) extends \Magento\Ui\Component\Action {
                public function __construct(private readonly string $type)
                {
                }

                public function getConfiguration()
                {
                    return ['type' => $this->type] + (array)$this->getData('config');
                }

                public function prepare()
                {
                }
            };
        }
        $massAction = new MassAction(
            $context,
            $this->auth(['Furan_FormBuilder::forms_delete']),
            $actions,
            ['acl' => ['delete' => 'Furan_FormBuilder::forms_delete', 'enable' => 'Furan_FormBuilder::forms_save']]
        );

        $massAction->prepare();

        $this->assertSame(['enable', 'disable'], array_column($massAction->getConfiguration()['actions'], 'type'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function action(string $type, array $config): \Magento\Ui\Component\Action
    {
        return new class ($type, $config) extends \Magento\Ui\Component\Action {
            /** @param array<string, mixed> $config */
            public function __construct(private readonly string $type, array $config)
            {
                $this->_data = ['config' => $config];
            }

            public function getConfiguration()
            {
                return ['type' => $this->type] + (array)$this->getData('config');
            }

            public function prepare()
            {
            }
        };
    }

    private function scopedMassActions(?string $formId): array
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));
        $context->method('getRequestParam')->willReturnCallback(static fn (string $key) => $key === 'form_id' ? $formId : null);
        $massAction = new MassAction($context, $this->auth([]), [
            'delete' => $this->action('delete', ['url' => '/admin/furanform/submission/massDelete/key/abc/']),
            'status' => $this->action('status', ['actions' => [
                ['type' => 'status_new', 'url' => '/admin/furanform/submission/massStatus/status/0/key/abc/'],
                ['type' => 'status_closed', 'url' => '/admin/furanform/submission/massStatus/status/3/key/abc/?x=1'],
            ]]),
        ]);
        $massAction->prepare();
        return $massAction->getConfiguration()['actions'];
    }

    public function testMassActionsOfAFormsGridCarryThatFormSoSelectAllStaysInsideIt(): void
    {
        $actions = $this->scopedMassActions('7');

        $this->assertSame('/admin/furanform/submission/massDelete/key/abc/?form_id=7', $actions[0]['url']);
        $this->assertSame('/admin/furanform/submission/massStatus/status/0/key/abc/?form_id=7', $actions[1]['actions'][0]['url']);
        $this->assertSame('/admin/furanform/submission/massStatus/status/3/key/abc/?x=1&form_id=7', $actions[1]['actions'][1]['url']);
    }

    public function testMassActionUrlsAreUntouchedOutsideAFormsGrid(): void
    {
        foreach ([null, '', '0', 'abc', '-3'] as $formId) {
            $actions = $this->scopedMassActions($formId);
            $this->assertSame('/admin/furanform/submission/massDelete/key/abc/', $actions[0]['url'], var_export($formId, true));
        }
    }

    public function testTheAddFormButtonNeedsSaveRights(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturn('/new');
        $this->assertSame([], (new AddButton($this->auth(['Furan_FormBuilder::forms_save']), $url))->getButtonData());
        $this->assertSame('Add New Form', (string)(new AddButton($this->auth([]), $url))->getButtonData()['label']);
    }

    public function testSubmissionRowsOfferDeleteOnlyWithDeleteRights(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturn('/x');
        $rows = ['data' => ['items' => [['submission_id' => '4']]]];
        foreach ([[[], ['view', 'delete']], [['Furan_FormBuilder::submissions_delete'], ['view']]] as [$denied, $expected]) {
            $column = new SubmissionActions(
                $this->createStub(ContextInterface::class),
                $this->createStub(UiComponentFactory::class),
                $url,
                $this->auth($denied),
                [],
                ['name' => 'actions']
            );
            $this->assertSame($expected, array_keys($column->prepareDataSource($rows)['data']['items'][0]['actions']));
        }
    }
}
