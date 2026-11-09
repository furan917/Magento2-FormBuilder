<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\FormInterface;

/** @api */
interface SpamCheckInterface
{
    public function check(SubmissionInput $input, FormInterface $form): SpamResult;
}
