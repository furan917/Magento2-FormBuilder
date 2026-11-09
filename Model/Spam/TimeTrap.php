<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SpamCheckInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Config;

class TimeTrap implements SpamCheckInterface
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly Config $config
    ) {
    }

    public function check(SubmissionInput $input, FormInterface $form): SpamResult
    {
        $token = $input->formToken;
        if ($token === '') {
            return SpamResult::reject('token');
        }
        $formId = (int)$form->getId();
        $minSeconds = $this->config->getMinSubmitSeconds($input->storeId);
        // Only a validly signed token learns that it was too early; anything else gets the generic refusal.
        $age = $this->tokenService->age($token, $formId);
        if ($age !== null && $age < $minSeconds) {
            return SpamResult::reject('too_early', __('Please wait a moment and try again.'), 425);
        }
        $valid = $this->tokenService->verify($token, $formId, $minSeconds);
        return $valid ? SpamResult::pass() : SpamResult::reject('token');
    }
}
