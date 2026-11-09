<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel;

use Furan\FormBuilder\Model\Submission\Reference;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Submission extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('furan_formbuilder_submission', 'submission_id');
    }

    /** Covers every insert path; updates never touch the reference. */
    protected function _beforeSave(AbstractModel $object)
    {
        $current = $object->getData('reference');
        if ($object->isObjectNew() && (!is_string($current) || strlen($current) !== Reference::BYTES)) {
            $object->setData('reference', Reference::generate());
        }

        return parent::_beforeSave($object);
    }

    /** Column-only write: a full save() would revert concurrent edits to other columns. */
    public function markRead(int $submissionId): bool
    {
        return $this->connection()->update(
            $this->getMainTable(),
            ['is_read' => 1],
            ['submission_id = ?' => $submissionId, 'is_read = ?' => 0]
        ) > 0;
    }

    public function markUnread(int $submissionId): bool
    {
        return $this->connection()->update(
            $this->getMainTable(),
            ['is_read' => 0],
            ['submission_id = ?' => $submissionId, 'is_read = ?' => 1]
        ) > 0;
    }

    /** Reads at most $limit ids. */
    public function countUpTo(int $limit): int
    {
        $connection = $this->connection();
        $ids = $connection->select()->from($this->getMainTable(), ['submission_id'])->limit($limit);

        return (int)$connection->fetchOne($connection->select()->from(['t' => $ids], ['COUNT(*)']));
    }

    /** @return int[] */
    public function customerFormIds(int $customerId): array
    {
        $connection = $this->connection();
        $select = $connection->select()->distinct()
            ->from($this->getMainTable(), ['form_id'])
            ->where('customer_id = ?', $customerId);

        return array_map('intval', $connection->fetchCol($select));
    }

    /** @param int[] $formIds */
    public function countForCustomer(int $customerId, array $formIds): int
    {
        if ($formIds === []) {
            return 0;
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['COUNT(*)'])
            ->where('customer_id = ?', $customerId)
            ->where('form_id IN (?)', array_map('intval', $formIds));

        return (int)$connection->fetchOne($select);
    }

    /** The default connection is always configured; AbstractDb types it as possibly false. */
    private function connection(): AdapterInterface
    {
        /** @var AdapterInterface $connection */
        $connection = $this->getConnection();
        return $connection;
    }
}
