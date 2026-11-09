<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Event;

use Magento\Framework\Event\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Status and thread events fire after the change is committed, so an observer failure is logged, never thrown.
 */
class SubmissionEvents
{
    public function __construct(
        private readonly ManagerInterface $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @param string $source admin|customer|system */
    public function statusChanged(int $submissionId, int $formId, int $oldStatus, int $newStatus, string $source): void
    {
        $this->dispatch('furan_formbuilder_submission_status_changed', [
            'submission_id' => $submissionId,
            'form_id' => $formId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'source' => $source,
        ]);
    }

    /** @param string $author admin|customer|guest */
    public function threadMessageAdded(int $submissionId, int $formId, string $author, int $messageId): void
    {
        $this->dispatch('furan_formbuilder_thread_message_added', [
            'submission_id' => $submissionId,
            'form_id' => $formId,
            'author' => $author,
            'message_id' => $messageId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatch(string $name, array $data): void
    {
        try {
            $this->eventManager->dispatch($name, $data);
        } catch (\Throwable $e) {
            $this->logger->error($name . ' observer failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
