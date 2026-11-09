<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

/**
 * Send-time check for addresses handed to the mail transport. A leading hyphen is refused because a sendmail
 * transport can read such an address as a command-line option.
 */
class SafeAddress
{
    /** @phpstan-assert-if-true non-empty-string $address */
    public static function isValid(mixed $address): bool
    {
        return is_string($address)
            && $address !== ''
            && !str_starts_with($address, '-')
            && preg_match('/[\r\n]/', $address) !== 1
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}
