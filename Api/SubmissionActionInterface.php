<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;

/** @api */
interface SubmissionActionInterface
{
    /** Whether this action runs for the given context; edits run only the actions that return true for them. */
    public function supports(ActionContext $context): bool;

    /**
     * Runs after a valid submission. Exceptions are logged and never shown to the visitor.
     *
     * @param array<string, string|array<int|string, mixed>> $values visible field values by name
     */
    public function execute(
        FormInterface $form,
        SubmissionInterface $submission,
        array $values,
        ActionContext $context
    ): void;
}
