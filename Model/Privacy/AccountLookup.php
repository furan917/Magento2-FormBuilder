<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Privacy;

use Magento\Framework\App\ResourceConnection;

/**
 * Customer accounts registered with an email. With per-website account sharing one address can own several.
 */
class AccountLookup
{
    private const MAX_ACCOUNTS = 100;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return int[] */
    public function customerIds(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }
        $ids = $this->resource->getConnection()->fetchCol(
            'SELECT entity_id FROM ' . $this->resource->getTableName('customer_entity')
            . ' WHERE email = ? ORDER BY entity_id LIMIT ' . self::MAX_ACCOUNTS,
            [$email]
        );

        return array_map('intval', $ids);
    }
}
