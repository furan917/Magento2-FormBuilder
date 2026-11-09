<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Submission\EditTarget;
use Furan\FormBuilder\Model\Submission\EditTargetResolver;
use Furan\FormBuilder\Model\Submission\EditToken;
use Furan\FormBuilder\Model\Submission\Result;
use Furan\FormBuilder\Model\Submission\SubmissionUpdater;
use Furan\FormBuilder\Model\Survey\Respondents;
use PHPUnit\Framework\TestCase;

class EditTargetResolverTest extends TestCase
{
    private function form(): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);

        return $form;
    }

    private function resolver(?int $tokenTarget = 11, ?array $respondent = null): EditTargetResolver
    {
        $token = $this->createStub(EditToken::class);
        $token->method('verify')->willReturn($tokenTarget);
        $respondents = $this->createStub(Respondents::class);
        $respondents->method('customerKey')->willReturn('c5');
        $respondents->method('find')->willReturn($respondent);
        $updater = $this->createStub(SubmissionUpdater::class);
        $updater->method('storedFiles')->willReturn(['cv' => ['file' => 'a.pdf']]);

        return new EditTargetResolver($token, $respondents, $updater);
    }

    private function loggedIn(string $token = ''): SubmissionInput
    {
        return new SubmissionInput(editToken: $token, customerId: 5);
    }

    public function testItRefusesAnExpiredEditLinkWithoutFallingBackToANewSubmission(): void
    {
        $settings = ['account_edit' => true];

        foreach ([
            [$this->resolver(null), $settings, $this->loggedIn('tok')],
            [$this->resolver(11), [], $this->loggedIn('tok')],
            [$this->resolver(11), $settings, new SubmissionInput(editToken: 'tok')],
        ] as [$resolver, $given, $input]) {
            $target = $resolver->resolve($this->form(), $given, $input);
            $this->assertInstanceOf(Result::class, $target);
            $this->assertSame(400, $target->getHttpCode());
            $this->assertSame(
                'This edit link has expired. Open the submission from your account again.',
                $target->getMessage()
            );
        }
    }

    public function testItResolvesAValidEditLinkToTheSubmissionAndItsStoredFiles(): void
    {
        $target = $this->resolver(11)->resolve($this->form(), ['account_edit' => true], $this->loggedIn('tok'));

        $this->assertSame(11, $target->submissionId);
        $this->assertSame(['cv'], $target->storedFileNames);
    }

    public function testItRefusesAnExpiredEditLinkFromTheEditTarget(): void
    {
        $link = $this->resolver(11)->resolve($this->form(), ['account_edit' => true], $this->loggedIn('tok'));
        $survey = $this->resolver(null, ['submission_id' => 21])
            ->resolve($this->form(), ['survey_mode' => 'edit'], $this->loggedIn());

        $this->assertInstanceOf(EditTarget::class, $link);
        $this->assertInstanceOf(EditTarget::class, $survey);
        $this->assertSame(
            'This edit link has expired. Open the submission from your account again.',
            $link->lostRefusal?->getMessage()
        );
        $this->assertNull($survey->lostRefusal);
    }

    public function testItResolvesASurveyEditToTheRespondentsSubmission(): void
    {
        $resolver = $this->resolver(null, ['submission_id' => 21]);

        $target = $resolver->resolve($this->form(), ['survey_mode' => 'edit'], $this->loggedIn());

        $this->assertSame(21, $target->submissionId);
        $this->assertSame(['cv'], $target->storedFileNames);
    }

    public function testItRefusesASurveyRespondentWhoseAnswerWasDetached(): void
    {
        $resolver = $this->resolver(null, ['submission_id' => null]);

        $target = $resolver->resolve($this->form(), ['survey_mode' => 'edit'], $this->loggedIn());

        $this->assertInstanceOf(Result::class, $target);
        $this->assertSame('You have already answered this form.', $target->getMessage());
    }

    public function testItTargetsNothingForANewSubmission(): void
    {
        $this->assertEquals(new EditTarget(null, []), $this->resolver()->resolve($this->form(), [], $this->loggedIn()));
        $this->assertEquals(
            new EditTarget(null, []),
            $this->resolver()->resolve($this->form(), ['survey_mode' => 'edit'], new SubmissionInput())
        );
    }
}
