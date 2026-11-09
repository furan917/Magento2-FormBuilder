<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

/** The mail transport refused the message, so nothing was sent and a retry cannot duplicate it. */
class MailNotSentException extends \RuntimeException
{
}
