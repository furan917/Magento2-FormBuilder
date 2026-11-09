<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Model\Config;

/** Whether customers and guests may add to a thread. Admins can always reply. */
class ReplyPolicy
{
    public const SETTING = 'customer_replies';

    public function __construct(
        private readonly Config $config,
        private readonly Messages $messages
    ) {
    }

    /**
     * The form's 'on'/'off' wins; 'default' or no value follows the store.
     *
     * @param array<string, mixed> $settings
     */
    public function customerRepliesOpen(array $settings, ?int $storeId): bool
    {
        return match ($settings[self::SETTING] ?? 'default') {
            'on' => true,
            'off' => false,
            default => $this->config->isCustomerRepliesEnabled($storeId),
        };
    }

    /** @param array<string, mixed> $settings */
    public function guestRepliesOpen(array $settings, ?int $storeId): bool
    {
        return $this->customerRepliesOpen($settings, $storeId) && $this->config->isGuestReplyLinksEnabled($storeId);
    }

    public static function fullMessage(): string
    {
        return (string)__('This conversation has reached its message limit. To ask something new, please contact us again.');
    }

    public function isFull(int $submissionId): bool
    {
        $max = $this->config->getMaxThreadMessages();

        return $max > 0 && $this->messages->countUpTo($submissionId, $max) >= $max;
    }
}
