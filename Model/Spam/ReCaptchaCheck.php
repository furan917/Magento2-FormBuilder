<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SpamCheckInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Api\SubmissionInput;
use Magento\Framework\Phrase;

class ReCaptchaCheck implements SpamCheckInterface
{
    public function __construct(private readonly ReCaptcha $reCaptcha)
    {
    }

    public function check(SubmissionInput $input, FormInterface $form): SpamResult
    {
        $message = $this->reCaptcha->verifyToken($input->recaptchaResponse);

        // The message already comes translated from the reCAPTCHA module.
        return $message === null ? SpamResult::pass() : SpamResult::reject('recaptcha', new Phrase($message));
    }
}
