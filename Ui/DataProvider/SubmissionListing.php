<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Ui\DataProvider;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Submission\Reference;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;

class SubmissionListing extends DataProvider
{
    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        private readonly FormRepositoryInterface $formRepository,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $meta,
            $data
        );
    }

    /** @return array<string, mixed> */
    public function getMeta()
    {
        $meta = parent::getMeta();
        if ((string)$this->request->getParam('form_id') !== '') {
            // The form is fixed by the link; without a filter, a Form chip remembered in the grid view cannot show.
            $meta['furan_formbuilder_submission_columns']['children']['form_id']['arguments']['data']['config']['filter'] = false;
        }
        return $meta;
    }

    public function addFilter(Filter $filter)
    {
        if ($filter->getField() === 'fulltext') {
            parent::addFilter($this->referenceFilter($filter->getValue()));
            return;
        }
        if ($filter->getField() === 'form_id') {
            // A form chosen via the link wins over a form filter remembered in the admin's saved grid view.
            $linked = (string)$this->request->getParam('form_id');
            if ($linked !== '' && (string)$filter->getValue() !== $linked) {
                return;
            }
            if (!$this->formExists($filter->getValue())) {
                // The linked form is gone: match nothing, or select all would reach every form's submissions.
                if ($linked !== '') {
                    parent::addFilter($this->filterBuilder->setField('submission_id')->setConditionType('eq')->setValue('0')->create());
                }
                // A remembered filter for a deleted form would silently empty the grid.
                return;
            }
        }
        parent::addFilter($filter);
    }

    /**
     * The grid has no FULLTEXT index, so search is an exact reference seek on the unique index; any other
     * keyword matches no row (submission ids start at 1) instead of scanning payloads.
     */
    private function referenceFilter(mixed $keyword): Filter
    {
        $bytes = is_string($keyword) ? Reference::parse($keyword) : null;
        $this->filterBuilder->setField($bytes !== null ? 'reference' : 'submission_id')
            ->setConditionType('eq')
            ->setValue($bytes ?? '0');

        return $this->filterBuilder->create();
    }

    private function formExists(mixed $value): bool
    {
        if (!is_scalar($value) || !ctype_digit((string)$value)) {
            return true;
        }
        try {
            $this->formRepository->getById((int)$value);
            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }
}
