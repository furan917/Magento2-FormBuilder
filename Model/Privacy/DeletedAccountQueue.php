<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Privacy;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Account deletion behaviour. Inside the customer delete, before its FK cascade nulls customer_id, the account's
 * submissions are written to furan_formbuilder_privacy_pending and so roll back with a failed delete. After the
 * commit only a wake-up is published; PendingProcessor does the work, with the cron sweep as fallback.
 */
class DeletedAccountQueue
{
    public const TOPIC = 'furan_formbuilder.privacy';

    /** @var array<int, true> */
    private array $pending = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly PublisherInterface $publisher,
        private readonly Json $json,
        private readonly Respondents $respondents
    ) {
    }

    public function capture(int $customerId): void
    {
        unset($this->pending[$customerId]);
        $mode = $this->config->getAccountDeletionBehaviour();
        if ($mode === 'keep') {
            return;
        }
        $connection = $this->resource->getConnection();
        $submissions = $this->resource->getTableName('furan_formbuilder_submission');
        // The delete would take this lock later. Taking it first makes a concurrent claim or submit wait on its
        // FK check and then fail, so no row joins the account between the statements below and the delete.
        $connection->fetchOne(
            'SELECT entity_id FROM ' . $this->resource->getTableName('customer_entity') . ' WHERE entity_id = ? FOR UPDATE',
            [$customerId]
        );
        // Unlinked rows with a fingerprint could be claimed by the same email before they are processed.
        $connection->update(
            $submissions,
            ['email_hash' => null, 'email_hash_version' => null],
            ['customer_id = ?' => $customerId]
        );
        $inserted = $connection->query(
            'INSERT INTO ' . $this->resource->getTableName('furan_formbuilder_privacy_pending')
            . ' (submission_id, customer_id, mode, next_attempt_at)'
            . ' SELECT submission_id, ?, ?, ? FROM ' . $submissions . ' WHERE customer_id = ?',
            [$customerId, $mode, gmdate('Y-m-d H:i:s'), $customerId]
        )->rowCount();
        $this->respondents->removeByKeys([$this->respondents->customerKey($customerId)]);
        if ($inserted > 0) {
            $this->pending[$customerId] = true;
        }
    }

    public function publish(int $customerId): void
    {
        if (!isset($this->pending[$customerId])) {
            return;
        }
        unset($this->pending[$customerId]);
        $this->publisher->publish(self::TOPIC, (string)$this->json->serialize(['customer_id' => $customerId]));
    }
}
