<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Customer;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Settings\FormSettings;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager;

class CustomerSummary
{
    private const OTHER_LIMIT = 10;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Manager $moduleManager
    ) {
    }

    /**
     * Order figures are only queried when $withOrders is true (Magento_Sales::sales_order).
     *
     * @param array<string, mixed> $formSettings
     * @return array{type: string, name: string, email: string, group: string,
     *     orders: ?array{count: int, lifetime: float, lastOrderAt: ?string}, others: array<int, array<string, mixed>>}
     */
    public function card(SubmissionInterface $submission, array $formSettings, bool $withOrders): array
    {
        $customerId = (int)$submission->getCustomerId();
        $profile = $customerId > 0 ? $this->profile($customerId) : null;
        if ($profile === null) {
            return [
                'type' => 'guest',
                'name' => '',
                'email' => $this->respondentEmail($submission->getPayload(), $formSettings),
                'group' => '',
                'orders' => null,
                'others' => [],
            ];
        }

        return [
            'type' => 'customer',
            'name' => $profile['name'],
            'email' => $profile['email'],
            'group' => $profile['group'],
            'orders' => $withOrders ? $this->orderStats($customerId) : null,
            'others' => $this->otherSubmissions($customerId, (int)$submission->getId()),
        ];
    }

    /**
     * @return array{name: string, email: string, group: string}|null
     */
    public function profile(int $customerId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['c' => $this->resource->getTableName('customer_entity')], ['firstname', 'lastname', 'email'])
                ->joinLeft(
                    ['g' => $this->resource->getTableName('customer_group')],
                    'g.customer_group_id = c.group_id',
                    ['group' => 'customer_group_code']
                )
                ->where('c.entity_id = ?', $customerId)
        );
        if (!is_array($row) || $row === []) {
            return null;
        }

        return [
            'name' => trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')),
            'email' => (string)($row['email'] ?? ''),
            'group' => (string)($row['group'] ?? ''),
        ];
    }

    /**
     * Null without Magento_Sales or its order table.
     *
     * @return array{count: int, lifetime: float, lastOrderAt: ?string}|null
     */
    public function orderStats(int $customerId): ?array
    {
        $connection = $this->resource->getConnection();
        $orders = $this->resource->getTableName('sales_order');
        if (!$this->moduleManager->isEnabled('Magento_Sales') || !$connection->isTableExists($orders)) {
            return null;
        }
        $row = (array)$connection->fetchRow(
            $connection->select()
                ->from($orders, [
                    'orders' => new \Zend_Db_Expr('COUNT(*)'),
                    'lifetime' => new \Zend_Db_Expr('SUM(base_grand_total * base_to_global_rate)'),
                    'last_order_at' => new \Zend_Db_Expr('MAX(created_at)'),
                ])
                ->where('customer_id = ?', $customerId)
                ->where('state <> ?', 'canceled')
        );

        return [
            'count' => (int)($row['orders'] ?? 0),
            'lifetime' => (float)($row['lifetime'] ?? 0),
            'lastOrderAt' => isset($row['last_order_at']) ? (string)$row['last_order_at'] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function otherSubmissions(int $customerId, int $excludeSubmissionId): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('furan_formbuilder_submission'),
                    ['submission_id', 'form_id', 'summary', 'created_at', 'status']
                )
                ->where('customer_id = ?', $customerId)
                ->where('submission_id <> ?', $excludeSubmissionId)
                ->order('submission_id DESC')
                ->limit(self::OTHER_LIMIT)
        );
    }

    /**
     * @param array<string, mixed>|null $context
     * @return array{name: string, sku: string, url: string, price: ?float, currency: string}|null
     */
    public function productContext(?array $context): ?array
    {
        if (!$context) {
            return null;
        }
        $url = (string)($context['url'] ?? '');

        return [
            'name' => (string)($context['name'] ?? ''),
            'sku' => (string)($context['sku'] ?? ''),
            'url' => preg_match('#^https?://#i', $url) === 1 ? $url : '',
            'price' => isset($context['price']) ? (float)$context['price'] : null,
            'currency' => (string)($context['currency'] ?? ''),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $payload
     * @param array<string, mixed> $settings
     */
    private function respondentEmail(array $payload, array $settings): string
    {
        $name = FormSettings::submitterEmailField($settings);
        if ($name === '') {
            return '';
        }
        foreach ($payload as $entry) {
            $value = $entry['value'] ?? null;
            if (($entry['name'] ?? null) === $name && is_string($value)) {
                return preg_match('/[\r\n]/', $value) !== 1 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                    ? $value
                    : '';
            }
        }

        return '';
    }
}
