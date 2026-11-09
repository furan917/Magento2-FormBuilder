<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

/**
 * The stored submission a request edits, or none for a new answer.
 */
class EditTarget
{
    /**
     * @param string[] $storedFileNames file fields that already hold a stored file
     * @param Result|null $lostRefusal the answer when the submission can no longer be edited; null for the generic one
     */
    public function __construct(
        public readonly ?int $submissionId,
        public readonly array $storedFileNames,
        public readonly ?Result $lostRefusal = null
    ) {
    }
}
