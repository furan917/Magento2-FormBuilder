<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class UnsafeUrlException extends LocalizedException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct($this->phraseFor($reason));
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    private function phraseFor(string $reason): Phrase
    {
        return match ($reason) {
            'scheme' => __('The URL must use https.'),
            'userinfo' => __('The URL must not contain a user name or password.'),
            'fragment' => __('The URL must not contain a fragment.'),
            'control_character', 'backslash', 'percent_in_authority', 'path_invalid'
                => __('The URL contains characters that are not allowed.'),
            'host_idn', 'host_invalid' => __('The URL host is not a valid public host name.'),
            'ip_literal_not_allowed' => __('IP addresses are not allowed as the URL host.'),
            'port' => __('The URL port is not allowed.'),
            'host_not_allowed' => __('The URL host is not on the allowed host list.'),
            'host_unresolved', 'address_not_public' => __('The URL host does not resolve to a public address.'),
            default => __('The URL is not valid.'),
        };
    }
}
