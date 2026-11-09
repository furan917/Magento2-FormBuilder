<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Ui\DataProvider;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Ui\DataProvider\SubmissionListing;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

class SubmissionListingTest extends TestCase
{
    /** @var Filter[] */
    private array $added = [];

    private function provider(?string $urlFormId, array $existing = [1, 4]): SubmissionListing
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('getById')->willReturnCallback(function (int $id) use ($existing) {
            if (!in_array($id, $existing, true)) {
                throw new NoSuchEntityException(__('gone'));
            }
            return $this->createStub(Form::class);
        });
        $this->added = [];
        $criteria = $this->createStub(SearchCriteriaBuilder::class);
        $criteria->method('addFilter')->willReturnCallback(function (Filter $f) use ($criteria) {
            $this->added[] = $f;
            return $criteria;
        });
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['form_id', null, $urlFormId]]);
        $builder = $this->createStub(FilterBuilder::class);
        $pending = new Filter();
        $builder->method('setField')->willReturnCallback(function ($v) use ($builder, &$pending) {
            $pending = new Filter();
            $pending->setField($v);
            return $builder;
        });
        $builder->method('setValue')->willReturnCallback(function ($v) use ($builder, &$pending) {
            $pending->setValue($v);
            return $builder;
        });
        $builder->method('setConditionType')->willReturnSelf();
        $builder->method('create')->willReturnCallback(function () use (&$pending) {
            return $pending;
        });
        return new SubmissionListing(
            'listing',
            'submission_id',
            'id',
            $this->createStub(ReportingInterface::class),
            $criteria,
            $request,
            $builder,
            $forms,
            [],
            ['config' => ['update_url' => '/render/', 'filter_url_params' => ['form_id' => '*']]]
        );
    }

    private function filter(string $field, string $value): Filter
    {
        return (new Filter())->setField($field)->setValue($value);
    }

    public function testItIgnoresARememberedFormFilterThatContradictsTheLinkedForm(): void
    {
        $provider = $this->provider('1');
        $provider->addFilter($this->filter('form_id', '4'));
        $this->assertSame([['form_id', '1']], array_map(fn(Filter $f) => [$f->getField(), $f->getValue()], $this->added));
    }

    public function testItKeepsOtherFiltersAndFormFiltersWithoutALinkedForm(): void
    {
        $provider = $this->provider(null);
        $provider->addFilter($this->filter('form_id', '4'));
        $provider->addFilter($this->filter('summary', 'x'));
        $this->assertSame(
            [['form_id', '4'], ['summary', 'x']],
            array_map(fn(Filter $f) => [$f->getField(), $f->getValue()], $this->added)
        );
    }

    public function testItIgnoresARememberedFormFilterWhoseFormNoLongerExists(): void
    {
        $provider = $this->provider(null, [1]);
        $provider->addFilter($this->filter('form_id', '4'));
        $provider->addFilter($this->filter('summary', 'x'));
        $this->assertSame([['summary', 'x']], array_map(fn(Filter $f) => [$f->getField(), $f->getValue()], $this->added));
    }

    public function testAFormsGridWhoseFormWasDeletedMatchesNothingSoSelectAllCannotWiden(): void
    {
        $this->provider('4', [1]);

        $this->assertSame([['submission_id', '0']], array_map(fn(Filter $f) => [$f->getField(), $f->getValue()], $this->added));
    }

    public function testItFindsASubmissionInTheAdminGridByReferenceWithoutHyphens(): void
    {
        $provider = $this->provider(null);
        $provider->addFilter($this->filter('fulltext', '  0194F3A0111172228333444455556666 '));

        $this->assertCount(1, $this->added);
        $this->assertSame('reference', $this->added[0]->getField());
        $this->assertSame(hex2bin('0194f3a0111172228333444455556666'), $this->added[0]->getValue());
    }

    public function testItFindsASubmissionInTheAdminGridByReferenceWithHyphens(): void
    {
        $provider = $this->provider(null);
        $provider->addFilter($this->filter('fulltext', '0194f3a0-1111-7222-8333-444455556666'));

        $this->assertSame('reference', $this->added[0]->getField());
        $this->assertSame(hex2bin('0194f3a0111172228333444455556666'), $this->added[0]->getValue());
    }

    public function testItFindsNothingForASearchKeywordThatIsNotAReference(): void
    {
        $provider = $this->provider(null);
        $provider->addFilter($this->filter('fulltext', 'john@example.com'));

        $this->assertCount(1, $this->added);
        $this->assertSame('submission_id', $this->added[0]->getField());
        $this->assertSame('0', $this->added[0]->getValue());
    }

    public function testItDropsTheFormFilterOnAFormsOwnGridSoNoRememberedFormChipShows(): void
    {
        $meta = $this->provider('4')->getMeta();

        $this->assertFalse($meta['furan_formbuilder_submission_columns']['children']['form_id']['arguments']['data']['config']['filter']);
        $this->assertSame([], $this->provider(null)->getMeta());
        $this->assertSame([], $this->provider('')->getMeta());
    }

    public function testTheFormFilterMetaNamesTheListingsColumns(): void
    {
        $listing = simplexml_load_file(__DIR__ . '/../../../../view/adminhtml/ui_component/furan_formbuilder_submission_listing.xml');

        $this->assertCount(1, $listing->xpath('//columns[@name="furan_formbuilder_submission_columns"]/column[@name="form_id"]/settings/filter'));
    }
}
