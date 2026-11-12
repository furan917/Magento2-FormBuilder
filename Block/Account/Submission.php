<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Account;

use Furan\FormBuilder\Api\Data\DisplayContext;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Submission\StoreDisplayContext;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\ReplyPolicy;
use Magento\Customer\Model\Session;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/** @phpstan-type SubmissionView array{title: string, reference: string, status: int, status_label: string, created_at: string, can_edit: bool, edit_closed: bool, can_delete: bool, one_response: bool, can_reply: bool, reply_state: string, thread: array<int, array<string, mixed>>, earlier: bool, before: ?int, groups: list<array<string, mixed>>} */
class Submission extends Template
{
    /** @var SubmissionView|array{}|null empty once computed as not found */
    private ?array $view = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly SubmissionReader $reader,
        private readonly FormRepositoryInterface $formRepository,
        private readonly AnswerView $answerView,
        private readonly Json $json,
        private readonly Session $session,
        private readonly Messages $messages,
        private readonly StoreManagerInterface $storeManager,
        private readonly MessageRenderer $renderer,
        private readonly ReplyPolicy $policy,
        private readonly SubmissionStatus $statuses,
        private readonly ScheduleGate $schedule,
        private readonly SubmissionPolicy $accountPolicy,
        private readonly StoreDisplayContext $displayContexts,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return SubmissionView|null */
    public function getView(): ?array
    {
        if ($this->view !== null) {
            return $this->view ?: null;
        }
        $this->view = [];
        $row = $this->reader->find((int)$this->session->getCustomerId(), (int)$this->getRequest()->getParam('id'));
        if ($row === null) {
            return null;
        }
        try {
            $form = $this->formRepository->getById((int)$row['form_id']);
            $schema = $form->getSchema();
            $title = $form->getTitle();
            $settings = $form->getSettings();
            $active = $form->isActive();
            $scheduleClosed = $this->schedule->refusal($form) !== null;
        } catch (NoSuchEntityException) {
            $schema = [];
            $title = '';
            $settings = [];
            $active = false;
            $scheduleClosed = false;
        }
        $status = (int)$row['status'];
        $payload = $this->json->unserialize((string)$row['payload_json']);
        $submissionId = (int)$row['submission_id'];
        $before = Messages::beforeParam($this->getRequest()->getParam('before'));
        // Settings, the store's name and the answers' locale come from the store the submission was made in, not the one being browsed.
        $storeId = isset($row['store_id']) ? (int)$row['store_id'] : null;
        $page = $this->messages->pageForCustomer($submissionId, $this->storeName($storeId), $before);
        $replyState = $this->replyState($status, $settings, $submissionId, $storeId);
        $editAllowed = $active && $this->accountPolicy->canEdit($settings, $status);
        $this->view = [
            'title' => $title,
            'reference' => (string)($row['reference'] ?? ''),
            'status' => $status,
            'status_label' => (string)$this->statuses->getLabel($status),
            'created_at' => (string)$row['created_at'],
            // The Edit controller re-checks store assignment and ownership.
            'can_edit' => $editAllowed && !$scheduleClosed,
            'edit_closed' => $editAllowed && $scheduleClosed,
            'can_delete' => $this->accountPolicy->canDelete($settings),
            'one_response' => ($settings['survey_mode'] ?? 'off') !== 'off',
            'can_reply' => $replyState === 'open',
            'reply_state' => $replyState,
            'thread' => $page['messages'],
            'earlier' => $page['earlier'],
            'before' => $before,
            'groups' => $this->visibleGroups(
                $schema,
                is_array($payload) ? $payload : [],
                $title,
                $this->displayContexts->forStore($storeId)
            ),
        ];
        return $this->view;
    }

    /** @param array<string, mixed> $message */
    public function renderMessage(array $message): string
    {
        return $this->renderer->html((string)($message['author'] ?? ''), (string)($message['body'] ?? ''));
    }

    public function getEditUrl(): string
    {
        return $this->getUrl('furanform/account/edit', ['id' => (int)$this->getRequest()->getParam('id')]);
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('furanform/account/delete', ['id' => (int)$this->getRequest()->getParam('id')]);
    }

    public function getReplyUrl(): string
    {
        return $this->getUrl('furanform/account/reply', ['id' => (int)$this->getRequest()->getParam('id')]);
    }

    public function getEarlierUrl(int $beforeId): string
    {
        return $this->getUrl('furanform/account/submission', [
            'id' => (int)$this->getRequest()->getParam('id'),
            'before' => $beforeId,
            '_fragment' => 'ffb-thread',
        ]);
    }

    public function getLatestUrl(): string
    {
        return $this->getUrl('furanform/account/submission', [
            'id' => (int)$this->getRequest()->getParam('id'),
            '_fragment' => 'ffb-thread',
        ]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('furanform/account/submissions');
    }

    /**
     * open | closed (status) | off (form or store setting) | full (thread size limit)
     *
     * @param array<string, mixed> $settings
     */
    private function replyState(int $status, array $settings, int $submissionId, ?int $storeId): string
    {
        return match (true) {
            $status === SubmissionInterface::STATUS_CLOSED => 'closed',
            !$this->policy->customerRepliesOpen($settings, $storeId) => 'off',
            $this->policy->isFull($submissionId) => 'full',
            default => 'open',
        };
    }

    private function storeName(?int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            $store = $this->storeManager->getStore();
        }
        /** @var Store $store */
        $name = trim((string)$store->getFrontendName());
        return $name !== '' ? $name : (string)__('Support');
    }

    /**
     * Customer-facing answers: hidden-type fields and unanswered questions are dropped, files show their name
     * only, and a page titled like the form is not repeated.
     *
     * @param array<string, mixed> $schema
     * @param array<int, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function visibleGroups(array $schema, array $payload, string $formTitle, DisplayContext $context): array
    {
        $groups = [];
        foreach ($this->answerView->group($schema, $payload) as $group) {
            $sections = [];
            foreach ($group['sections'] as $section) {
                $entries = [];
                foreach ($section['entries'] as $entry) {
                    if ($this->answerView->isServerValue($entry)) {
                        continue;
                    }
                    $values = array_values(array_filter(
                        $this->entryValues($entry, $context),
                        static fn ($value): bool => trim((string)$value) !== ''
                    ));
                    if ($values === []) {
                        continue;
                    }
                    $entries[] = [
                        'label' => (string)($entry['label'] ?? $entry['name'] ?? ''),
                        'values' => $values,
                    ];
                }
                if ($entries) {
                    $sections[] = ['title' => $section['title'], 'entries' => $entries];
                }
            }
            if ($sections) {
                $groups[] = ['title' => $group['title'] === $formTitle ? '' : $group['title'], 'sections' => $sections];
            }
        }
        return $groups;
    }

    /**
     * @param array<string, mixed> $entry
     * @return string[]
     */
    private function entryValues(array $entry, DisplayContext $context): array
    {
        if ($this->answerView->isFile($entry)) {
            $name = is_array($entry['value'] ?? null) ? (string)($entry['value']['name'] ?? '') : '';
            return $name === '' ? [] : [$name];
        }
        return $this->answerView->values($entry, $context);
    }
}
