<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\Result;
use PHPUnit\Framework\TestCase;

class ResultTest extends TestCase
{
    public function testItBuildsTheSuccessResult(): void
    {
        $result = Result::success('Thanks', '/thanks', 'ref-1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->getHttpCode());
        $this->assertSame('Thanks', $result->getMessage());
        $this->assertSame('/thanks', $result->getRedirect());
        $this->assertSame('ref-1', $result->getReference());
        $this->assertSame([], $result->getErrors());
    }

    public function testItBuildsTheRefusalResults(): void
    {
        $refused = Result::refused('No', 403);
        $this->assertFalse($refused->isSuccess());
        $this->assertSame([403, 'No'], [$refused->getHttpCode(), $refused->getMessage()]);

        $invalid = Result::invalid(['email' => 'Bad']);
        $this->assertSame(422, $invalid->getHttpCode());
        $this->assertSame('Please fix the highlighted fields.', $invalid->getMessage());
        $this->assertSame(['email' => 'Bad'], $invalid->getErrors());
        $this->assertSame('Custom', Result::invalid(['email' => 'Bad'], 'Custom')->getMessage());

        $this->assertSame([400, 'Please refresh the page and try again.'], $this->codeAndMessage(Result::refresh()));
        $this->assertSame(
            [429, 'Too many submissions. Please wait a few minutes and try again.'],
            $this->codeAndMessage(Result::tooMany())
        );
        $this->assertSame([500, 'Something went wrong. Please try again.'], $this->codeAndMessage(Result::failed()));
    }

    /** @return array{int, string} */
    private function codeAndMessage(Result $result): array
    {
        $this->assertFalse($result->isSuccess());

        return [$result->getHttpCode(), $result->getMessage()];
    }
}
