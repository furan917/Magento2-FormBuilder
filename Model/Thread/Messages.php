<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Event\SubmissionEvents;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Psr\Log\LoggerInterface;

class Messages
{
    private const TABLE = 'furan_formbuilder_message';
    private const SUBMISSION_TABLE = 'furan_formbuilder_submission';
    public const PAGE_SIZE = 100;
    public const MAX_BODY_LENGTH = 5000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubmissionEvents $events,
        private readonly StatusWriter $statusWriter,
        private readonly SubmissionResource $submissions,
        private readonly LoggerInterface $logger
    ) {
    }

    public function add(int $submissionId, ?int $adminUserId, string $body): int
    {
        return $this->insert($submissionId, 'admin', 'admin', $adminUserId, $body);
    }

    /**
     * Also flags the submission unread for the grid; the reply is already stored, so that failure is only logged.
     *
     * @param string $author customer|guest: both are stored as "customer"
     */
    public function addCustomer(int $submissionId, string $body, string $author): int
    {
        $messageId = $this->insert($submissionId, 'customer', $author, null, $body);
        try {
            $this->submissions->markUnread($submissionId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Form Builder could not mark a submission unread after a customer reply.',
                ['exception_class' => $e::class, 'submission_id' => $submissionId]
            );
        }

        return $messageId;
    }

    /**
     * One transaction with its webhook deliveries; no journal: status is not an analytics dimension.
     * The reply is already stored, so a failure here is logged and the status stays answered.
     */
    public function reopenIfAnswered(int $submissionId): void
    {
        try {
            $this->statusWriter->change(
                [$submissionId],
                SubmissionInterface::STATUS_IN_PROGRESS,
                'customer',
                SubmissionInterface::STATUS_ANSWERED
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Form Builder could not reopen an answered submission after a customer reply.',
                ['exception_class' => $e::class, 'submission_id' => $submissionId]
            );
        }
    }

    private function insert(int $submissionId, string $stored, string $author, ?int $adminUserId, string $body): int
    {
        /** @var Mysql $connection lastInsertId() is not on AdapterInterface */
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->insert($table, [
            'submission_id' => $submissionId,
            'author' => $stored,
            'admin_user_id' => $adminUserId,
            'body' => $body,
        ]);
        $messageId = (int)$connection->lastInsertId($table);
        $this->events->threadMessageAdded($submissionId, $this->formId($submissionId), $author, $messageId);

        return $messageId;
    }

    private function formId(int $submissionId): int
    {
        return (int)$this->resource->getConnection()->fetchOne(
            'SELECT form_id FROM ' . $this->resource->getTableName(self::SUBMISSION_TABLE) . ' WHERE submission_id = ?',
            [$submissionId]
        );
    }

    /** @return array{submission_id: int, body: string}|null */
    public function customerMessage(int $messageId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['submission_id', 'body'])
                ->where('message_id = ?', $messageId)
                ->where('author = ?', 'customer')
        );

        return is_array($row) ? ['submission_id' => (int)$row['submission_id'], 'body' => (string)$row['body']] : null;
    }

    public function markEmailed(int $messageId): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            ['emailed_at' => new \Zend_Db_Expr('NOW()')],
            $connection->quoteInto('message_id = ?', $messageId)
        );
    }

    /**
     * Latest PAGE_SIZE messages (before $beforeId when given), oldest first, each with an author_name.
     *
     * @return array{messages: array<int, array<string, mixed>>, earlier: bool}
     */
    public function page(int $submissionId, ?int $beforeId = null): array
    {
        $page = $this->fetchPage($submissionId, $beforeId, ['*']);
        $names = $this->adminNames($page['messages']);
        foreach ($page['messages'] as &$row) {
            $row['author_name'] = $row['author'] === 'customer'
                ? (string)__('Customer')
                : ($names[(int)$row['admin_user_id']] ?? (string)__('Admin'));
        }
        unset($row);

        return $page;
    }

    /**
     * Thread for the customer: admin identities are never read, authors are "You" or the store name.
     *
     * @return array{messages: array<int, array<string, mixed>>, earlier: bool}
     */
    public function pageForCustomer(int $submissionId, string $storeName, ?int $beforeId = null): array
    {
        $page = $this->fetchPage($submissionId, $beforeId, ['message_id', 'author', 'body', 'created_at']);
        foreach ($page['messages'] as &$row) {
            $row['author_name'] = $row['author'] === 'customer' ? (string)__('You') : $storeName;
        }
        unset($row);

        return $page;
    }

    /** Keyset cursor from a request: digits only, so "12abc", -1 or before[]= fall back to the latest page. */
    public static function beforeParam(mixed $raw): ?int
    {
        return is_string($raw) && ctype_digit($raw) && strlen($raw) <= 10 && (int)$raw > 0 ? (int)$raw : null;
    }

    /** Thread size, read through the (submission_id, message_id) index; stops counting after $cap + 1. */
    public function countUpTo(int $submissionId, int $cap): int
    {
        $connection = $this->resource->getConnection();
        $inner = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['one' => new \Zend_Db_Expr('1')])
            ->where('submission_id = ?', $submissionId)
            ->limit($cap + 1);

        return (int)$connection->fetchOne(
            $connection->select()->from(['t' => $inner], ['n' => new \Zend_Db_Expr('COUNT(*)')])
        );
    }

    /**
     * @param string[] $columns
     * @return array{messages: array<int, array<string, mixed>>, earlier: bool}
     */
    private function fetchPage(int $submissionId, ?int $beforeId, array $columns): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), $columns)
            ->where('submission_id = ?', $submissionId);
        if ($beforeId !== null) {
            $select->where('message_id < ?', $beforeId);
        }
        $rows = $connection->fetchAll($select->order('message_id DESC')->limit(self::PAGE_SIZE + 1));
        $earlier = count($rows) > self::PAGE_SIZE;

        return ['messages' => array_reverse(array_slice($rows, 0, self::PAGE_SIZE)), 'earlier' => $earlier];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function adminNames(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)$row['admin_user_id'],
            $rows
        ))));
        if ($ids === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $users = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('admin_user'), ['user_id', 'firstname', 'lastname'])
                ->where('user_id IN (?)', $ids)
        );
        $names = [];
        foreach ($users as $user) {
            $names[(int)$user['user_id']] = trim($user['firstname'] . ' ' . $user['lastname']);
        }

        return $names;
    }
}
