<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Model\Submission\Reference;

/** Standard Webhooks message id: msg_ plus 32 lower-case hex (a time-ordered UUIDv7). */
class MessageId
{
    public static function generate(): string
    {
        return 'msg_' . bin2hex(Reference::generate());
    }
}
