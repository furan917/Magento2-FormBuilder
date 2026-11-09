<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Settings;

/** Settings an admin may only change with a specific ACL resource; without it the stored values win. */
class ProtectedSettings
{
    public const SUBMISSIONS_RESOURCE = 'Furan_FormBuilder::submissions';
    public const SUBMISSION_SETTINGS = [
        'notify_enabled', 'notify_recipients', 'notify_cc', 'notify_bcc', 'notify_subject', 'notify_routes', 'notify_when',
        'store_ip',
    ];

    public function __construct(private readonly ContributorPool $contributors)
    {
    }

    /**
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $stored
     * @param callable(string): bool $isAllowed
     * @return array<string, mixed>
     */
    public function restrict(array $posted, array $stored, callable $isAllowed): array
    {
        $settings = $isAllowed(self::SUBMISSIONS_RESOURCE) ? $posted : $this->withStoredSubmissionSettings($posted, $stored);
        return $this->withStoredContributorSettings($settings, $stored, $isAllowed);
    }

    /**
     * @param callable(string): bool $isAllowed
     * @return list<string>
     */
    public function hiddenKeys(callable $isAllowed): array
    {
        return $isAllowed(self::SUBMISSIONS_RESOURCE) ? [] : self::SUBMISSION_SETTINGS;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function withStoredSubmissionSettings(array $settings, array $stored): array
    {
        foreach (self::SUBMISSION_SETTINGS as $key) {
            unset($settings[$key]);
            if (array_key_exists($key, $stored)) {
                $settings[$key] = $stored[$key];
            }
        }
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $stored
     * @param callable(string): bool $isAllowed
     * @return array<string, mixed>
     */
    private function withStoredContributorSettings(array $settings, array $stored, callable $isAllowed): array
    {
        foreach ($this->contributors->all() as $code => $contributor) {
            $resource = $contributor->getAclResource();
            if ($resource === null || $isAllowed($resource)) {
                continue;
            }
            $settings['ext'] = is_array($settings['ext'] ?? null) ? $settings['ext'] : [];
            unset($settings['ext'][$code]);
            if (isset($stored['ext'][$code])) {
                $settings['ext'][$code] = $stored['ext'][$code];
            }
        }
        return $settings;
    }
}
