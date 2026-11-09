<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Model\Condition\Today;

/**
 * Decides email conditions (routes, send when) from what was stored: the payload values, the submission's store
 * and the day it was created, so inline, queued and retried runs reach the same answer.
 */
class EmailConditions
{
    public function __construct(
        private readonly Evaluator $evaluator,
        private readonly Today $today
    ) {
    }

    /**
     * @return array{values: array<string, mixed>, fields: array<string, array<string, mixed>>, today: string}
     */
    public function context(FormInterface $form, SubmissionInterface $submission, int $storeId): array
    {
        return [
            'values' => $this->valuesOf($submission),
            'fields' => $this->evaluator->fieldsByName($form->getSchema()),
            'today' => $this->today->forStore($storeId, $submission->getCreatedAt()),
        ];
    }

    /**
     * A missing condition means "always"; a condition without rules never matches (see matches()).
     *
     * @param array{values: array<string, mixed>, fields: array<string, array<string, mixed>>, today: string} $context
     */
    public function allows(mixed $condition, array $context): bool
    {
        return $condition === null || $this->matches($condition, $context);
    }

    /**
     * @param array{values: array<string, mixed>, fields: array<string, array<string, mixed>>, today: string} $context
     */
    public function matches(mixed $condition, array $context): bool
    {
        return is_array($condition)
            && $this->evaluator->matches($condition, $context['values'], $context['fields'], $context['today']);
    }

    /** @return array<string, mixed> */
    public function valuesOf(SubmissionInterface $submission): array
    {
        $values = [];
        foreach ($submission->getPayload() as $entry) {
            $values[(string)($entry['name'] ?? '')] = $entry['value'] ?? '';
        }

        return $values;
    }
}
