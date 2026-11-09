<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Action;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Handler of the furan_formbuilder.action queue. Never throws: failures are logged and acknowledged, because a
 * queue-level redelivery would run email actions twice. Retries are opt-in and go through the retry scheduler.
 * Actions get the stored answers (file fields left out), which differ from the posted values for hidden fields.
 */
class ActionConsumer
{
    public function __construct(
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Translator $translator,
        private readonly Pool $fieldTypes,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly SubmissionActionPool $actions,
        private readonly ActionExecutor $executor,
        private readonly StoreScope $storeScope,
        private readonly SubmissionStore $submissionStore
    ) {
    }

    public function process(string $message): void
    {
        try {
            $data = $this->json->unserialize($message);
            $this->run(
                (int)($data['id'] ?? 0),
                (string)($data['action'] ?? ''),
                max(1, (int)($data['attempt'] ?? 1)),
                !empty($data['edit'])
            );
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder action queue: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    public function run(int $id, string $key, int $attempt, bool $isEdit): void
    {
        try {
            $submission = $this->submissionRepository->getById($id);
            $form = $this->formRepository->getById($submission->getFormId());
        } catch (NoSuchEntityException) {
            // Erased or deleted before the consumer got to it.
            return;
        }
        $action = $this->actions->deferrable()[$key] ?? null;
        if ($action === null) {
            $this->logger->warning('Form Builder action queue: unknown action "' . $key . '" dropped.');
            return;
        }
        $storeId = $this->submissionStore->resolve($submission, $form);
        $customerId = $submission->getCustomerId();
        $context = new ActionContext($isEdit, $storeId, $customerId, $attempt);
        $display = $this->translator->localize($form, $storeId);
        if (!$action->isApplicable($display) || !$action->supports($context)) {
            return;
        }
        // Outside any storefront request: run as the submission's store so code-built text uses its language.
        $this->storeScope->run(
            $storeId,
            fn () => $this->executor->run($key, $action, $display, $submission, $this->values($submission), $context)
        );
    }

    /**
     * @return array<string, string|array<int|string, mixed>>
     */
    private function values(SubmissionInterface $submission): array
    {
        $values = [];
        foreach ($submission->getPayload() as $entry) {
            if (!$this->fieldTypes->isFile((string)($entry['type'] ?? ''))) {
                $values[(string)($entry['name'] ?? '')] = $entry['value'] ?? '';
            }
        }

        return $values;
    }
}
