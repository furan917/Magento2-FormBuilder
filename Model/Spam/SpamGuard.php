<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SpamCheckInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Api\ValuesSpamCheckInterface;
use Furan\FormBuilder\Api\SubmissionInput;

class SpamGuard
{
    /**
     * @param array<string, mixed> $checks
     */
    public function __construct(private readonly array $checks = [])
    {
    }

    /** Request stage, before validation. The first check that does not pass decides. */
    public function check(SubmissionInput $input, FormInterface $form): SpamResult
    {
        foreach ($this->checks as $check) {
            if (!$check instanceof SpamCheckInterface) {
                continue;
            }
            $result = $check->check($input, $form);
            if (!$result->isPass()) {
                return $result;
            }
        }
        return SpamResult::pass();
    }

    /**
     * Values stage, after validation, before anything is stored.
     *
     * @param array<string, mixed> $values
     */
    public function checkValues(FormInterface $form, array $values, SubmissionInput $input): SpamResult
    {
        foreach ($this->checks as $check) {
            if (!$check instanceof ValuesSpamCheckInterface) {
                continue;
            }
            $result = $check->checkValues($form, $values, $input);
            if (!$result->isPass()) {
                return $result;
            }
        }
        return SpamResult::pass();
    }
}
