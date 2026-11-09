<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Row, store, recipient and counter are re-read per request: delete, anonymise or revoke ends links and grants.
 *
 * @phpstan-type ThreadContext array{submission: SubmissionInterface, form: FormInterface, version: int, fingerprints: array<int, string>, recipient: string}
 */
class ThreadAccess
{
    private const TABLE = 'furan_formbuilder_submission';

    /** Frontend URL model: the backend one would build admin URLs from the reply controller. */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ThreadToken $token,
        private readonly EmailHasher $emailHasher,
        private readonly RecipientResolver $recipients,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Url $url,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /** Owners go to My submissions; guests get a fresh signed link. '' when no link can be issued. */
    public function replyUrl(SubmissionInterface $submission, string $recipient): string
    {
        $id = (int)$submission->getId();
        if ($submission->getStoreId() === null) {
            return '';
        }
        try {
            // A Store object, not an id: the store code in the URL is read from it only then.
            $store = $this->storeManager->getStore((int)$submission->getStoreId());
        } catch (NoSuchEntityException) {
            return '';
        }
        $params = ['_scope' => $store, '_scope_to_url' => true, '_nosid' => true];
        if ((int)$submission->getCustomerId() > 0) {
            return $this->url->getUrl('furanform/account/submission', $params + ['_query' => ['id' => $id]]);
        }
        $fingerprint = $this->emailHasher->hash($recipient);
        $version = $this->version($id);
        $token = $fingerprint === null || $version === null
            ? null
            : $this->token->issue($id, $fingerprint['hash'], $version);

        return $token === null ? '' : $this->url->getUrl('furanform/thread/view', $params + ['_query' => ['t' => $token]]);
    }

    /**
     * Status UPDATE bind; closing a submission also revokes its guest reply links.
     *
     * @return array<string, int|\Zend_Db_Expr>
     */
    public static function statusBind(int $status): array
    {
        $bind = ['status' => $status, 'updated_at' => new \Zend_Db_Expr('NOW()')];
        if ($status === SubmissionInterface::STATUS_CLOSED) {
            $bind['thread_token_version'] = new \Zend_Db_Expr('thread_token_version + 1');
        }
        return $bind;
    }

    /**
     * @param int[] $submissionIds
     * @return int rows changed
     */
    public function revoke(array $submissionIds): int
    {
        $ids = array_values(array_filter(array_map('intval', $submissionIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        return (int)$connection->update(
            $this->resource->getTableName(self::TABLE),
            ['thread_token_version' => new \Zend_Db_Expr('thread_token_version + 1')],
            $connection->quoteInto('submission_id IN (?)', $ids)
        );
    }

    /**
     * @return ThreadContext|null
     */
    public function redeem(string $token, int $storeId): ?array
    {
        $parsed = $this->token->parse($token);
        $context = $parsed === null ? null : $this->load($parsed['id'], $storeId);

        return $context !== null && $this->token->matches($parsed, $context['fingerprints'], $context['version'])
            ? $context
            : null;
    }

    /**
     * @return array{submission: SubmissionInterface, form: FormInterface, version: int, fingerprints: array<int, string>, recipient: string}|null
     */
    public function load(int $submissionId, int $storeId): ?array
    {
        if ($submissionId < 1) {
            return null;
        }
        try {
            $submission = $this->submissionRepository->getById($submissionId);
            $form = $this->formRepository->getById($submission->getFormId());
        } catch (NoSuchEntityException) {
            return null;
        }
        if ($submission->getStoreId() === null || (int)$submission->getStoreId() !== $storeId) {
            return null;
        }
        $recipient = $this->recipients->resolve($submission, $form);
        $fingerprints = $recipient === null ? [] : $this->emailHasher->candidates($recipient);
        $version = $this->version($submissionId);
        if ($fingerprints === [] || $version === null) {
            return null;
        }

        return [
            'submission' => $submission,
            'form' => $form,
            'version' => $version,
            'fingerprints' => $fingerprints,
            'recipient' => (string)$recipient,
        ];
    }

    private function version(int $submissionId): ?int
    {
        $connection = $this->resource->getConnection();
        /** @var string|false|null $version fetchOne() gives false for no row; its docblock says string. */
        $version = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['thread_token_version'])
                ->where('submission_id = ?', $submissionId)
        );

        return $version === false || $version === null ? null : (int)$version;
    }
}
