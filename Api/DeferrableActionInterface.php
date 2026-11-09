<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\FormInterface;

/**
 * An action the processor may hand to the action queue consumer instead of running it in the submit request.
 * It must work from the stored submission alone: the consumer has no request, session or file uploads.
 * Queued runs are one message per action, gated by `furan_formbuilder/general/async_actions`.
 *
 * @api
 */
interface DeferrableActionInterface extends SubmissionActionInterface
{
    /** Cheap settings check, so submissions that would send nothing are not queued. */
    public function isApplicable(FormInterface $form): bool;
}
