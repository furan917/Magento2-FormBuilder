<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Export;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/** Large CSV exports are built by the furan_formbuilder.export consumer instead of inside the admin request. */
class ExportQueue
{
    public const TOPIC = 'furan_formbuilder.export';
    public const INLINE_LIMIT = 20000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly PublisherInterface $publisher,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Reads at most INLINE_LIMIT + 1 index entries, never the whole form. */
    public function isLarge(int $formId): bool
    {
        return (int)$this->resource->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->resource->getTableName('furan_formbuilder_submission')
            . ' WHERE form_id = ? LIMIT ' . (self::INLINE_LIMIT + 1) . ') t',
            [$formId]
        ) > self::INLINE_LIMIT;
    }

    public function request(int $formId): bool
    {
        try {
            $this->publisher->publish(self::TOPIC, $this->json->serialize(['form_id' => $formId]));
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder could not queue a CSV export: ' . $e->getMessage());
            return false;
        }
    }
}
