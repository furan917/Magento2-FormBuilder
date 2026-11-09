<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Action;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Model\Submission\SubmissionStore;

class ActionRunner
{
    public function __construct(
        private readonly SubmissionActionPool $pool,
        private readonly ActionQueue $queue,
        private readonly ActionExecutor $executor,
        private readonly SubmissionStore $submissionStore
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function run(FormInterface $form, SubmissionInterface $submission, array $values, bool $isEdit = false): void
    {
        $storeId = $this->submissionStore->resolve($submission, $form);
        $customerId = $submission->getCustomerId();
        $context = new ActionContext($isEdit, $storeId, $customerId, 1);
        // Only stored submissions can be queued: the message carries the id, never the answers.
        $storedId = (int)$submission->getId();
        foreach ($this->pool->all() as $poolKey => $action) {
            $key = (string)$poolKey;
            if (!$action->supports($context)) {
                continue;
            }
            if ($action instanceof DeferrableActionInterface && $action->isApplicable($form)
                && $storedId > 0 && $this->queue->publish($storedId, $key, 1, $isEdit)
            ) {
                continue;
            }
            $this->executor->run($key, $action, $form, $submission, $values, $context);
        }
    }
}
