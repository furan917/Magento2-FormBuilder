<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

use Furan\FormBuilder\Model\ResourceModel\SavedRule\CollectionFactory;

/**
 * @phpstan-type Filters array{q: string, usage: string, sort: string, limit: int, page: int}
 * @phpstan-type Row array{id?: int, code: string, label: string, pattern: string, example: string, updated_at: string, used: int}
 *
 * Search, usage filter, sort and paging for the saved rules admin list. The rule library is small by design
 * (one row per reusable format), so it is read once per request and filtered in memory.
 */
class SavedRuleList
{
    public const LIMITS = [20, 50, 100];
    public const SORTS = ['name', 'updated', 'usage'];
    public const USAGES = ['all', 'used', 'unused'];
    private const MAX_QUERY = 100;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SavedRuleManager $manager
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return Filters
     */
    public static function filters(array $params): array
    {
        $pick = static fn (mixed $v, array $allowed): string => in_array($v, $allowed, true) ? $v : $allowed[0];
        $limit = (int)($params['limit'] ?? 0);
        return [
            'q' => mb_substr(trim(is_string($params['q'] ?? null) ? $params['q'] : ''), 0, self::MAX_QUERY),
            'usage' => $pick($params['usage'] ?? null, self::USAGES),
            'sort' => $pick($params['sort'] ?? null, self::SORTS),
            'limit' => in_array($limit, self::LIMITS, true) ? $limit : self::LIMITS[0],
            'page' => max(1, (int)($params['p'] ?? 1)),
        ];
    }

    /**
     * @param Filters $filters
     * @return array{rows: list<Row>, total: int, all: int, page: int, pages: int, from: int, to: int}
     */
    public function page(array $filters): array
    {
        $rows = [];
        $collection = $this->collectionFactory->create()
            ->addFieldToSelect(['rule_id', 'code', 'label', 'pattern', 'example', 'updated_at']);
        foreach ($collection as $rule) {
            $rows[] = [
                'id' => (int)$rule->getId(),
                'code' => (string)$rule->getData('code'),
                'label' => (string)$rule->getData('label'),
                'pattern' => (string)$rule->getData('pattern'),
                'example' => (string)$rule->getData('example'),
                'updated_at' => (string)$rule->getData('updated_at'),
            ];
        }
        return self::apply($rows, $this->manager->usageCounts(array_column($rows, 'code')), $filters);
    }

    /**
     * @param array<int, array{code: string, label: string, pattern: string, example: string, updated_at: string}> $rows
     * @param array<string, int> $counts
     * @param Filters $filters
     * @return array{rows: list<Row>, total: int, all: int, page: int, pages: int, from: int, to: int}
     */
    public static function apply(array $rows, array $counts, array $filters): array
    {
        $all = count($rows);
        $q = mb_strtolower($filters['q']);
        $rows = array_values(array_filter(
            array_map(static fn (array $r): array => $r + ['used' => $counts[$r['code']] ?? 0], $rows),
            static function (array $r) use ($q, $filters): bool {
                if ($filters['usage'] === 'used' && $r['used'] === 0 || $filters['usage'] === 'unused' && $r['used'] > 0) {
                    return false;
                }
                return $q === '' || str_contains(
                    mb_strtolower($r['label'] . "\n" . $r['code'] . "\n" . $r['example'] . "\n" . $r['pattern']),
                    $q
                );
            }
        ));
        usort($rows, static fn (array $a, array $b): int => match ($filters['sort']) {
            'updated' => [$b['updated_at'], $a['label']] <=> [$a['updated_at'], $b['label']],
            'usage' => [$b['used'], strcasecmp($a['label'], $b['label'])] <=> [$a['used'], 0],
            default => strcasecmp($a['label'], $b['label']),
        });
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $filters['limit']));
        $page = min($filters['page'], $pages);
        $offset = ($page - 1) * $filters['limit'];
        return [
            'rows' => array_slice($rows, $offset, $filters['limit']),
            'total' => $total,
            'all' => $all,
            'page' => $page,
            'pages' => $pages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $filters['limit']),
        ];
    }
}
