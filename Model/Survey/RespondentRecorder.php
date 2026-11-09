<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Survey;

use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Settings\FormSettings;

class RespondentRecorder
{
    public function __construct(private readonly Respondents $respondents)
    {
    }

    /**
     * Runs inside the submission transaction; email keys are recorded but never block a customer.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $values
     * @throws AlreadyAnsweredException
     */
    public function record(int $formId, array $settings, array $values, int $submissionId, SubmissionInput $input): void
    {
        if (($settings['survey_mode'] ?? 'off') === 'off') {
            return;
        }
        $email = !empty($settings['guest_dedupe']) ? FormSettings::submitterEmail($input, $settings, $values) : '';
        $emailKey = $email === '' ? null : $this->respondents->emailKey($email);
        if ($input->isLoggedIn()) {
            $this->respondents->add(
                $formId,
                $this->respondents->customerKey($input->customerId),
                $submissionId
            );
            if ($emailKey !== null) {
                $this->respondents->addIfAbsent($formId, $emailKey, $submissionId);
            }
            return;
        }
        if ($emailKey !== null) {
            $this->respondents->assertNotAnswered($formId, $email);
            $this->respondents->add($formId, $emailKey, $submissionId);
        }
    }
}
