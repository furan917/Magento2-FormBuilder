<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Thread\MessageRenderer;

/** The `submission` template variable: a plain array, never the model (its getData() exposes private columns). */
class SubmissionVars
{
    public function __construct(private readonly SubmissionStatus $statuses)
    {
    }

    /** @return array{id: int, created_at: string, status: string, reference: string} */
    public function toArray(SubmissionInterface $submission): array
    {
        return [
            'id' => (int)$submission->getId(),
            'created_at' => MessageRenderer::braces((string)$submission->getCreatedAt()),
            'status' => MessageRenderer::braces((string)$this->statuses->getLabel($submission->getStatus())),
            'reference' => MessageRenderer::braces($submission->getReference()),
        ];
    }
}
