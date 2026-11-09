<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\FormInterface;

/**
 * A spam check that runs after validation, on the normalized values, before anything is stored.
 * Register it in the Model\Spam\SpamGuard `checks` pool. Never log the values.
 *
 * @api
 */
interface ValuesSpamCheckInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function checkValues(FormInterface $form, array $values, SubmissionInput $input): SpamResult;
}
