<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Access;

class FormAccess
{
    public const LOGIN = 'login';
    public const GROUP = 'group';

    /**
     * @return string|null null when allowed, 'login' when a guest must log in, 'group' when the group is not allowed
     * @param array<string, mixed> $settings
     */
    public function check(array $settings, bool $loggedIn, int $groupId): ?string
    {
        $groups = array_map('intval', (array)($settings['access_groups'] ?? []));
        if (!$loggedIn) {
            $needsLogin = !empty($settings['access_login_required']) || ($groups !== [] && !in_array(0, $groups, true));
            return $needsLogin ? self::LOGIN : null;
        }

        return $groups !== [] && !in_array($groupId, $groups, true) ? self::GROUP : null;
    }
}
