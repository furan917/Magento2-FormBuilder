<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup\CollectionFactory;

/**
 * One page of field groups for the admin list, ordered by name (unique). The page after a group starts after
 * that group's own name (read by primary key), so a cursor in the URL cannot inject a name and no OFFSET is used.
 * A cursor whose group is gone yields an empty page, so "load more" cannot loop.
 * The fields and translations columns are never selected.
 */
class FieldGroupList
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @param int $afterId id of the last group of the previous page, 0 for the first page
     * @return array{rows: list<array{id: int, name: string, description: string, updated_at: string}>, next: ?int}
     */
    public function page(int $afterId = 0, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $collection = $this->collectionFactory->create()
            ->addFieldToSelect(['group_id', 'name', 'description', 'updated_at']);
        $select = $collection->getSelect();
        if ($afterId > 0) {
            $afterName = $this->nameOf($afterId);
            if ($afterName === null) {
                return ['rows' => [], 'next' => null];
            }
            $select->where($collection->getConnection()->quoteInto('main_table.name > ?', $afterName));
        }
        $select->order('main_table.name ASC')->limit($limit + 1);

        $rows = [];
        foreach ($collection as $group) {
            $rows[] = [
                'id' => (int)$group->getId(),
                'name' => (string)$group->getData('name'),
                'description' => (string)$group->getData('description'),
                'updated_at' => (string)$group->getData('updated_at'),
            ];
        }
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return ['rows' => $rows, 'next' => $hasMore ? $rows[$limit - 1]['id'] : null];
    }

    private function nameOf(int $id): ?string
    {
        /** @var FieldGroup $group */
        $group = $this->collectionFactory->create()
            ->addFieldToSelect(['group_id', 'name'])
            ->addFieldToFilter('group_id', (string)$id)
            ->getFirstItem();
        return $group->getId() ? (string)$group->getData('name') : null;
    }
}
