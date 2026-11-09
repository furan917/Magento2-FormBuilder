<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;

/** Index hints for the endpoint's delivery list, found by columns because index names differ with a table prefix. */
class DeliveryIndex
{
    private const ENDPOINT = ['endpoint_id', 'delivery_id'];
    private const ENDPOINT_STATUS = ['endpoint_id', 'status', 'delivery_id'];

    /** @var array<string, string>|null columns joined by "," => hint */
    private ?array $hints = null;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** " FORCE INDEX (`…`)" for the (endpoint_id, delivery_id) index, or '' when it is missing. */
    public function forEndpoint(): string
    {
        return $this->hint(self::ENDPOINT);
    }

    /** " FORCE INDEX (`…`)" for the (endpoint_id, status, delivery_id) index, or '' when it is missing. */
    public function forEndpointStatus(): string
    {
        return $this->hint(self::ENDPOINT_STATUS);
    }

    /** @param list<string> $columns */
    private function hint(array $columns): string
    {
        if ($this->hints === null) {
            $this->hints = [];
            $connection = $this->resource->getConnection();
            foreach ($connection->getIndexList($this->resource->getTableName(Outbox::TABLE)) as $name => $index) {
                $key = implode(',', array_map(strtolower(...), (array)($index['COLUMNS_LIST'] ?? [])));
                $this->hints[$key] ??= ' FORCE INDEX (' . $connection->quoteIdentifier((string)$name) . ')';
            }
        }

        return $this->hints[implode(',', $columns)] ?? '';
    }
}
