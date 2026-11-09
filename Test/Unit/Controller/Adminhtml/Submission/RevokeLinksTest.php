<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\Submission\RevokeLinks;
use Furan\FormBuilder\Model\Thread\ThreadAccess;

class RevokeLinksTest extends SubmissionControllerTestCase
{
    public function testItRevokesTheSubmissionsGuestReplyLinksAndReturnsToTheView(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('7');
        $access = $this->createMock(ThreadAccess::class);
        $access->expects($this->once())->method('revoke')->with([7])->willReturn(1);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/view', ['id' => 7])->willReturnSelf();

        $this->assertSame($this->redirect, (new RevokeLinks($this->context, $access))->execute());
    }

    public function testItReportsAMissingSubmission(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('99');
        $access = $this->createStub(ThreadAccess::class);
        $access->method('revoke')->willReturn(0);
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        (new RevokeLinks($this->context, $access))->execute();
    }
}
