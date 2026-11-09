<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Account;

use Furan\FormBuilder\Api\Data\SubmissionInterface;

/** Defaults match SettingsNormalizer. */
class SubmissionPolicy
{
    private const EDITABLE_STATUSES = [SubmissionInterface::STATUS_NEW, SubmissionInterface::STATUS_IN_PROGRESS];

    /** @param array<string, mixed> $settings normalised form settings */
    public function canDelete(array $settings): bool
    {
        return (bool)($settings['account_delete'] ?? true);
    }

    /** @param array<string, mixed> $settings normalised form settings */
    public function canEdit(array $settings, int $status): bool
    {
        return !empty($settings['account_edit']) && $this->isEditableStatus($status);
    }

    public function isEditableStatus(int $status): bool
    {
        return in_array($status, self::EDITABLE_STATUSES, true);
    }
}
