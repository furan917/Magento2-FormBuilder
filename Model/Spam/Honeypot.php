<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SpamCheckInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Api\SubmissionInput;

class Honeypot implements SpamCheckInterface
{
    public function check(SubmissionInput $input, FormInterface $form): SpamResult
    {
        return $input->honeypot === '' ? SpamResult::pass() : SpamResult::silent('honeypot');
    }
}
