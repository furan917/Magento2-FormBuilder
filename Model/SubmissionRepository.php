<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\Data\SubmissionSearchResultsInterface;
use Furan\FormBuilder\Api\Data\SubmissionSearchResultsInterfaceFactory;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\ResourceModel\Submission\CollectionFactory;
use Furan\FormBuilder\Model\Search\KeysetCriteria;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Submission\Reference;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;

class SubmissionRepository implements SubmissionRepositoryInterface
{
    private const RANGE = ['eq', 'in', 'gt', 'gteq', 'lt', 'lteq'];
    /**
     * Every field but updated_at leads an index. Only eq keeps (column, submission_id) in id order; in, neq, nin
     * and like would sort or scan.
     */
    private const FILTERABLE = [
        'submission_id' => self::RANGE,
        'form_id' => ['eq'],
        'status' => ['eq'],
        'store_id' => ['eq'],
        'customer_id' => ['eq'],
        'is_read' => ['eq'],
        'created_at' => ['gt', 'gteq', 'lt', 'lteq'],
        'updated_at' => ['gt', 'gteq', 'lt', 'lteq'],
    ];
    /** Equality filters whose index can carry an updated_at filter, which has no index of its own. */
    private const ANCHORS = ['form_id', 'status', 'store_id', 'customer_id', 'is_read'];

    public function __construct(
        private readonly SubmissionResource $resource,
        private readonly SubmissionFactory $submissionFactory,
        private readonly Journal $journal,
        private readonly SubmissionDeliveryCleaner $deliveryCleaner,
        private readonly CollectionFactory $collectionFactory,
        private readonly SubmissionSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly KeysetCriteria $keyset
    ) {
    }

    public function save(SubmissionInterface $submission): SubmissionInterface
    {
        try {
            $this->resource->save($submission); // @phpstan-ignore argument.type (every implementation of the Api model is an AbstractModel)
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the submission.'), $e);
        }
        return $submission;
    }

    public function getById(int $submissionId): SubmissionInterface
    {
        $submission = $this->submissionFactory->create();
        $this->resource->load($submission, $submissionId);
        if (!$submission->getId()) {
            throw new NoSuchEntityException(__('Submission with id "%1" does not exist.', $submissionId));
        }
        return $submission;
    }

    public function getByReference(string $reference): SubmissionInterface
    {
        $bytes = Reference::parse($reference);
        $submission = $this->submissionFactory->create();
        if ($bytes !== null) {
            $this->resource->load($submission, $bytes, SubmissionInterface::REFERENCE);
        }
        if ($bytes === null || !$submission->getId()) {
            throw new NoSuchEntityException(__('The submission reference does not exist.'));
        }
        return $submission;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SubmissionSearchResultsInterface
    {
        $pageSize = $this->keyset->pageSize($searchCriteria, 'submission_id');
        $orders = $this->keyset->sortOrders($searchCriteria, [], 'submission_id');
        $filters = $this->keyset->filters($searchCriteria, self::FILTERABLE);
        $fields = array_column($filters, 'field');
        if (in_array('updated_at', $fields, true) && array_intersect($fields, self::ANCHORS) === []) {
            throw new InputException(__(
                'Filtering by "%1" also needs a filter on one of: %2.',
                'updated_at',
                implode(', ', self::ANCHORS)
            ));
        }
        $collection = $this->collectionFactory->create();
        $this->keyset->apply($collection, $filters, $orders, $pageSize);
        return $this->keyset->fill(
            $this->searchResultsFactory->create(),
            $searchCriteria,
            $collection->getItems(),
            $pageSize
        );
    }

    public function delete(SubmissionInterface $submission): bool
    {
        $this->deliveryCleaner->deleteFor([(int)$submission->getId()]);
        $this->resource->beginTransaction();
        try {
            $row = $this->lockRow((int)$submission->getId());
            if ($row !== null) {
                $this->journal->remove([$row]);
            }
            $this->resource->delete($submission); // @phpstan-ignore argument.type (every implementation of the Api model is an AbstractModel)
            $this->resource->commit();
        } catch (\Throwable $e) {
            $this->resource->rollBack();
            // The exception constructor only accepts \Exception as the cause; an \Error must not escape unwrapped.
            throw new CouldNotDeleteException(
                __('Could not delete the submission.'),
                $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e)
            );
        }
        return true;
    }

    public function deleteById(int $submissionId): bool
    {
        return $this->delete($this->getById($submissionId));
    }

    /**
     * Locking read: the latest committed row, so the journal matches what is actually deleted.
     *
     * @return array<string, mixed>|null
     */
    private function lockRow(int $submissionId): ?array
    {
        /** @var AdapterInterface $connection */
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getMainTable(),
                ['submission_id', 'form_id', 'payload_json', 'created_at', 'store_id', 'customer_group_id']
            )
            ->where('submission_id = ?', $submissionId)
            ->forUpdate(true);
        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }
}
