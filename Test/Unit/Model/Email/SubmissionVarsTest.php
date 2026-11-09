<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Email;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Email\SubmissionVars;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use PHPUnit\Framework\TestCase;

class SubmissionVarsTest extends TestCase
{
    public function testItPassesTheReferenceToSubmissionEmails(): void
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(9);
        $submission->method('getCreatedAt')->willReturn('2026-11-07 10:00:00');
        $submission->method('getStatus')->willReturn(SubmissionInterface::STATUS_IN_PROGRESS);
        $submission->method('getReference')->willReturn('0194f3a0-1111-7222-8333-444455556666');

        $this->assertSame(
            [
                'id' => 9,
                'created_at' => '2026-11-07 10:00:00',
                'status' => 'In progress',
                'reference' => '0194f3a0-1111-7222-8333-444455556666',
            ],
            (new SubmissionVars(new SubmissionStatus()))->toArray($submission)
        );
    }

    public function testItBraceEncodesEveryStringInTheSubmissionData(): void
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(9);
        $submission->method('getCreatedAt')->willReturn('{{var x}}');

        $data = (new SubmissionVars(new SubmissionStatus()))->toArray($submission);

        $this->assertSame('&#123;&#123;var x&#125;&#125;', $data['created_at']);
    }
}
