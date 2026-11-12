<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View;

use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\ViewModel\Submission\GridNotice;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\TestCase;

class SubmissionGridTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../..';

    private function listing(): \SimpleXMLElement
    {
        return simplexml_load_file(self::MODULE . '/view/adminhtml/ui_component/furan_formbuilder_submission_listing.xml');
    }

    /** @return array<string, string[]> index name => columns */
    private function submissionIndexes(): array
    {
        $schema = simplexml_load_file(self::MODULE . '/etc/db_schema.xml');
        $table = $schema->xpath('//table[@name="furan_formbuilder_submission"]')[0];
        $indexes = ['PRIMARY' => ['submission_id']];
        foreach ($table->index as $index) {
            $indexes[(string)$index['referenceId']] = array_map(
                static fn (\SimpleXMLElement $c): string => (string)$c['name'],
                iterator_to_array($index->column, false)
            );
        }
        foreach ($table->constraint as $constraint) {
            if (count($constraint->column) === 0) {
                continue;
            }
            $indexes[(string)$constraint['referenceId']] = array_map(
                static fn (\SimpleXMLElement $c): string => (string)$c['name'],
                iterator_to_array($constraint->column, false)
            );
        }

        return $indexes;
    }

    public function testItListsColumnsWithStatusBesideTheSummaryAndActionsLast(): void
    {
        $names = array_map(
            static fn (\SimpleXMLElement $column): string => (string)$column['name'],
            $this->listing()->xpath('//columns/*')
        );

        $this->assertSame(
            ['ids', 'submission_id', 'reference', 'form_id', 'summary', 'status', 'store_id', 'customer_id', 'is_read', 'created_at', 'actions'],
            $names
        );
        $store = $this->listing()->xpath('//columns/column[@name="store_id"]')[0];
        $this->assertSame(\Furan\FormBuilder\Ui\Component\Listing\Column\StoreView::class, (string)$store['class']);
    }

    public function testItOffersOnlyIndexBackedFiltersAndSorts(): void
    {
        $this->assertCount(0, $this->listing()->xpath('//columns/column[@name="summary"]/settings/filter'));
        $isRead = $this->listing()->xpath('//columns/column[@name="is_read"]')[0];
        $this->assertSame('false', (string)$isRead->settings->sortable);
        $form = $this->listing()->xpath('//columns/column[@name="form_id"]')[0];
        $this->assertSame('false', (string)$form->settings->sortable, 'Form shows titles but would sort by id');

        $leading = array_map(static fn (array $columns): string => $columns[0], $this->submissionIndexes());
        foreach ($this->listing()->xpath('//columns/column') as $column) {
            $settings = $column->settings;
            if ((string)$settings->sortable !== 'false' || (string)$settings->filter !== '') {
                $this->assertContains((string)$column['name'], $leading, (string)$column['name'] . ' has no index to seek');
            }
        }
        $this->assertContains(['is_read', 'submission_id'], array_values($this->submissionIndexes()));
        $whitelist = json_decode((string)file_get_contents(self::MODULE . '/etc/db_schema_whitelist.json'), true);
        $this->assertTrue($whitelist['furan_formbuilder_submission']['index']['FURAN_FORMBUILDER_SUBMISSION_IS_READ_SUBMISSION_ID']);
    }

    private function viewModel(bool $visible, string $cap): GridNotice
    {
        $viewModel = $this->createStub(GridNotice::class);
        $viewModel->method('isVisible')->willReturn($visible);
        $viewModel->method('formattedCap')->willReturn($cap);

        return $viewModel;
    }

    private function render(GridNotice $viewModel): string
    {
        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturn($viewModel);
        $escaper = EscaperFactory::create();

        return (static function () use ($escaper, $block): string {
            ob_start();
            include self::MODULE . '/view/adminhtml/templates/submission/grid-notice.phtml';

            return (string)ob_get_clean();
        })();
    }

    public function testItHidesTheGridCapNoticeBelowTheCap(): void
    {
        $this->assertSame('', trim($this->render($this->viewModel(false, '10,000'))));
    }

    public function testItShowsTheGridCapNoticeAboveTheListing(): void
    {
        $layout = simplexml_load_file(self::MODULE . '/view/adminhtml/layout/furanform_submission_index.xml');
        $names = [];
        foreach ($layout->xpath('//referenceContainer[@name="content"]/*') as $child) {
            $names[] = (string)$child['name'];
        }
        $this->assertSame([
            'furan_formbuilder_submission_grid_notice',
            'furan_formbuilder_submission_analytics_link',
            'furan_formbuilder_submission_listing',
        ], $names);
        $block = $layout->xpath('//block[@name="furan_formbuilder_submission_grid_notice"]')[0];
        $this->assertSame('Furan_FormBuilder::submission/grid-notice.phtml', (string)$block['template']);

        $argument = $block->xpath('arguments/argument[@name="view_model"]')[0];
        $this->assertSame('object', (string)$argument->attributes('http://www.w3.org/2001/XMLSchema-instance')['type']);
        $this->assertSame(GridNotice::class, (string)$argument);

        $html = $this->render($this->viewModel(true, '10.000'));

        $this->assertStringContainsString('The list shows at most 10.000 matching submissions.', $html);
        $this->assertStringContainsString('Select all acts on every matching submission, including any beyond 10.000.', $html);
        $this->assertStringContainsString('class="message message-notice', $html);
    }

    public function testItLinksAGridOpenedForOneFormToItsAnalytics(): void
    {
        $render = function (string $url): string {
            $viewModel = $this->createStub(\Furan\FormBuilder\ViewModel\Submission\AnalyticsLink::class);
            $viewModel->method('url')->willReturn($url);
            $block = $this->createStub(Template::class);
            $block->method('getData')->willReturn($viewModel);
            $escaper = EscaperFactory::create();

            return (static function () use ($escaper, $block): string {
                ob_start();
                include self::MODULE . '/view/adminhtml/templates/submission/analytics-link.phtml';

                return (string)ob_get_clean();
            })();
        };

        $this->assertSame('', trim($render('')));
        $html = $render('https://admin.test/furanform/analytics/index/form_id/7/?a=1&b="2"');
        $this->assertStringContainsString('href="https://admin.test/furanform/analytics/index/form_id/7/?a=1&amp;b=&quot;2&quot;"', $html);
        $this->assertStringContainsString('View analytics for this form', $html);
    }
}
