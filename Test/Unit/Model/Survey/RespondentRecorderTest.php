<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Survey;

use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Survey\AlreadyAnsweredException;
use Furan\FormBuilder\Model\Survey\RespondentRecorder;
use Furan\FormBuilder\Model\Survey\Respondents;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RespondentRecorderTest extends TestCase
{
    private Respondents&MockObject $respondents;

    private function recorder(): RespondentRecorder
    {
        $this->respondents = $this->createMock(Respondents::class);
        $this->respondents->method('customerKey')->willReturnCallback(static fn (int $id): string => 'c' . $id);
        $this->respondents->method('emailKey')->willReturnCallback(static fn (string $e): string => 'e' . $e);

        return new RespondentRecorder($this->respondents);
    }

    public function testItRecordsNothingWhenTheFormIsNotASurvey(): void
    {
        $recorder = $this->recorder();
        $this->respondents->expects($this->never())->method('add');

        $recorder->record(7, [], [], 11, new SubmissionInput(customerId: 5));
    }

    public function testItBlocksALoggedInCustomerByTheirCustomerKeyAndRecordsTheEmailKeyWithoutBlocking(): void
    {
        $recorder = $this->recorder();
        $this->respondents->expects($this->once())->method('add')->with(7, 'c5', 11);
        $this->respondents->expects($this->once())->method('addIfAbsent')->with(7, 'ea@x.test', 11);

        $recorder->record(
            7,
            ['survey_mode' => 'once', 'guest_dedupe' => true],
            [],
            11,
            new SubmissionInput(customerId: 5, customerEmail: 'a@x.test')
        );
    }

    public function testItChecksAndRecordsAGuestEmailAndPropagatesADuplicate(): void
    {
        $recorder = $this->recorder();
        $this->respondents->expects($this->once())->method('assertNotAnswered')->with(7, 'g@x.test')
            ->willThrowException(new AlreadyAnsweredException());
        $this->respondents->expects($this->never())->method('add');

        $this->expectException(AlreadyAnsweredException::class);
        $recorder->record(
            7,
            ['survey_mode' => 'once', 'guest_dedupe' => true, 'email_field' => 'mail'],
            ['mail' => 'g@x.test'],
            11,
            new SubmissionInput()
        );
    }

    public function testItRecordsAGuestRespondentWithoutTheSubmissionBuilder(): void
    {
        $recorder = $this->recorder();
        $this->respondents->expects($this->once())->method('assertNotAnswered')->with(7, 'g@x.test');
        $this->respondents->expects($this->once())->method('add')->with(7, 'eg@x.test', 11);

        $recorder->record(
            7,
            ['survey_mode' => 'once', 'guest_dedupe' => true, 'autoresponder_email_field' => 'mail'],
            ['mail' => 'g@x.test'],
            11,
            new SubmissionInput()
        );
    }

    public function testItRecordsNothingForAGuestWithoutDedupe(): void
    {
        $recorder = $this->recorder();
        $this->respondents->expects($this->never())->method('assertNotAnswered');

        $recorder->record(7, ['survey_mode' => 'once'], ['mail' => 'g@x.test'], 11, new SubmissionInput());
    }
}
