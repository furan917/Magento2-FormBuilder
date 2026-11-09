<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Furan\FormBuilder\Model\Spam\ReCaptchaCheck;
use Furan\FormBuilder\Api\SubmissionInput;
use PHPUnit\Framework\TestCase;

class ReCaptchaCheckTest extends TestCase
{
    private function verdict(?string $message): \Furan\FormBuilder\Api\SpamResult
    {
        $request = new SubmissionInput(recaptchaResponse: 'captcha-token');
        $captcha = $this->createMock(ReCaptcha::class);
        $captcha->expects($this->once())->method('verifyToken')->with('captcha-token')->willReturn($message);

        return (new ReCaptchaCheck($captcha))->check($request, $this->createStub(FormInterface::class));
    }

    public function testItPassesWhenReCaptchaHasNoComplaint(): void
    {
        $this->assertTrue($this->verdict(null)->isPass());
    }

    public function testItRejectsWithTheReCaptchaMessage(): void
    {
        $result = $this->verdict('reCAPTCHA verification failed.');

        $this->assertTrue($result->isReject());
        $this->assertSame('recaptcha', $result->getCode());
        $this->assertSame(400, $result->getHttpCode());
        $this->assertSame('reCAPTCHA verification failed.', (string)$result->getMessage());
    }
}
