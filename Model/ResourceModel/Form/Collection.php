<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel\Form;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'form_id';

    protected function _construct()
    {
        $this->_init(Form::class, FormResource::class);
        // The store filter joins a table that also has form_id.
        foreach (['form_id', 'identifier', 'title', 'is_active', 'created_at', 'updated_at'] as $column) {
            $this->addFilterToMap($column, 'main_table.' . $column);
        }
    }

    protected function _afterLoad()
    {
        /** @var FormResource $resource */
        $resource = $this->getResource();
        foreach ($this->_items as $item) {
            if ($item->hasData('schema_json')) {
                $item->setData('schema_json', $resource->upgradeSchemaJson((string)$item->getData('schema_json')));
            }
        }
        // A column-only select (addFieldToSelect) is a lookup that does not need the store links.
        $ids = [];
        foreach ($this->_fieldsToSelect === null ? $this->_items : [] as $item) {
            $ids[] = (int)$item->getData('form_id');
        }
        if ($ids) {
            $storeIds = $this->fetchStoreIds($ids);
            foreach ($this->_items as $item) {
                $item->setData('store_ids', $storeIds[(int)$item->getData('form_id')] ?? []);
            }
        }
        return parent::_afterLoad();
    }

    /**
     * @param int[] $formIds
     * @return array<int, int[]>
     */
    private function fetchStoreIds(array $formIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(FormResource::TABLE_FORM_STORE), ['form_id', 'store_id'])
            ->where('form_id IN (?)', $formIds);
        $storeIds = [];
        foreach ($connection->fetchAll($select) as $row) {
            $storeIds[(int)$row['form_id']][] = (int)$row['store_id'];
        }
        return $storeIds;
    }

    /**
     * Forms assigned to the given store view or to all store views (0).
     */
    public function addStoreFilter(int $storeId): self
    {
        $this->getSelect()
            ->join(
                ['store_link' => $this->getTable(FormResource::TABLE_FORM_STORE)],
                'store_link.form_id = main_table.form_id',
                []
            )
            ->where('store_link.store_id IN (?)', array_unique([0, $storeId]))
            ->distinct(true);
        return $this;
    }
}
