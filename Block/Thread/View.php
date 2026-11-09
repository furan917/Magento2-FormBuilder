<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Thread;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Thread\CurrentThread;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\ReplyPolicy;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;

/**
 * Guest thread page: title, date, status and conversation only. Answers are never shown here.
 *
 * @phpstan-type ThreadView array{id: int, title: string, created_at: string, status: int, status_label: string, closed: bool, can_reply: bool, reply_state: string, thread: array<int, array<string, mixed>>, earlier: bool, before: ?int}
 */
class View extends Template
{
    /** @var ThreadView|array{}|null empty once computed as not found */
    private ?array $view = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly CurrentThread $currentThread,
        private readonly Messages $messages,
        private readonly MessageRenderer $renderer,
        private readonly Impersonation $impersonation,
        private readonly ReplyPolicy $policy,
        private readonly SubmissionStatus $statuses,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return ThreadView|null */
    public function getView(): ?array
    {
        if ($this->view !== null) {
            return $this->view ?: null;
        }
        $this->view = [];
        $context = $this->currentThread->get();
        if ($context === null) {
            return null;
        }
        $submission = $context['submission'];
        $status = (int)$submission->getStatus();
        $closed = $status === SubmissionInterface::STATUS_CLOSED;
        $id = (int)$submission->getId();
        $before = Messages::beforeParam($this->getRequest()->getParam('before'));
        $page = $this->messages->pageForCustomer($id, $this->storeName(), $before);
        $replyState = match (true) {
            $closed => 'closed',
            $this->impersonation->isActive() => 'impersonating',
            !$this->policy->guestRepliesOpen(
                $context['form']->getSettings(),
                (int)$this->_storeManager->getStore()->getId()
            ) => 'off',
            $this->policy->isFull($id) => 'full',
            default => 'open',
        };
        $this->view = [
            'id' => $id,
            'title' => $context['form']->getTitle(),
            'created_at' => (string)$submission->getCreatedAt(),
            'status' => $status,
            'status_label' => (string)$this->statuses->getLabel($status),
            'closed' => $closed,
            'can_reply' => $replyState === 'open',
            'reply_state' => $replyState,
            'thread' => $page['messages'],
            'earlier' => $page['earlier'],
            'before' => $before,
        ];
        return $this->view;
    }

    /** @param array<string, mixed> $message */
    public function renderMessage(array $message): string
    {
        return $this->renderer->html((string)($message['author'] ?? ''), (string)($message['body'] ?? ''));
    }

    public function getReplyUrl(): string
    {
        return $this->getUrl('furanform/thread/reply', ['_query' => ['id' => (int)($this->getView()['id'] ?? 0)], '_scope_to_url' => true]);
    }

    public function getEarlierUrl(int $beforeId): string
    {
        return $this->getUrl('furanform/thread/view', [
            '_query' => ['id' => (int)($this->getView()['id'] ?? 0), 'before' => $beforeId],
            '_fragment' => 'ffb-thread',
            '_scope_to_url' => true,
        ]);
    }

    public function getLatestUrl(): string
    {
        return $this->getUrl('furanform/thread/view', [
            '_query' => ['id' => (int)($this->getView()['id'] ?? 0)],
            '_fragment' => 'ffb-thread',
            '_scope_to_url' => true,
        ]);
    }

    private function storeName(): string
    {
        /** @var Store $store */
        $store = $this->_storeManager->getStore();
        $name = trim((string)$store->getFrontendName());
        return $name !== '' ? $name : (string)__('Support');
    }
}
