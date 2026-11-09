<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Survey\Respondents;

/**
 * Which stored submission a request edits: the one an edit link names, or a survey respondent's own.
 */
class EditTargetResolver
{
    public function __construct(
        private readonly EditToken $editToken,
        private readonly Respondents $respondents,
        private readonly SubmissionUpdater $submissionUpdater
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     * @return EditTarget|Result a refusal when the request may not edit
     */
    public function resolve(FormInterface $form, array $settings, SubmissionInput $input): EditTarget|Result
    {
        if ($input->editToken !== '') {
            // A posted edit token never falls back to inserting a second copy.
            $editId = $this->linkTarget($form, $settings, $input);

            return $editId === null ? $this->expiredLink() : $this->target($editId, $this->expiredLink());
        }
        if (!$input->isLoggedIn() || ($settings['survey_mode'] ?? 'off') !== 'edit') {
            return new EditTarget(null, []);
        }
        $respondent = $this->respondents->find(
            (int)$form->getId(),
            [$this->respondents->customerKey($input->customerId)]
        );
        if ($respondent === null) {
            return new EditTarget(null, []);
        }
        if ($respondent['submission_id'] === null) {
            return Result::refused((string)__('You have already answered this form.'), 400);
        }

        return $this->target($respondent['submission_id']);
    }

    private function expiredLink(): Result
    {
        return Result::refused((string)__('This edit link has expired. Open the submission from your account again.'), 400);
    }

    private function target(int $submissionId, ?Result $lostRefusal = null): EditTarget
    {
        // A stored file satisfies "required" when the field is left empty.
        return new EditTarget($submissionId, array_keys($this->submissionUpdater->storedFiles($submissionId)), $lostRefusal);
    }

    /**
     * @param array<string, mixed> $settings
     * @return int|null the submission the token names; ownership, status and form are re-checked under the update lock
     */
    private function linkTarget(FormInterface $form, array $settings, SubmissionInput $input): ?int
    {
        if (!$input->isLoggedIn() || empty($settings['account_edit'])) {
            return null;
        }

        return $this->editToken->verify($input->editToken, $input->customerId, (int)$form->getId());
    }
}
