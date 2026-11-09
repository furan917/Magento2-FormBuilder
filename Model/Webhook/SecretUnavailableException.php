<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** The endpoint secret is empty, undecryptable or malformed; nothing may be signed with it. */
class SecretUnavailableException extends \RuntimeException
{
}
