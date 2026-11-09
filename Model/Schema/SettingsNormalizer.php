<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Model\Email\SafeAddress;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Model\Condition\ConditionNormalizer;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Form\Schedule;
use Furan\FormBuilder\Model\Settings\ContributorPool;
use Furan\FormBuilder\Model\Settings\FormSettings;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Magento\Customer\Api\Data\GroupInterface;

class SettingsNormalizer
{
    private const MAX_EMAILS = 10;
    private const DRAFT_MODES = ['session', 'local', 'off'];
    private const SURVEY_MODES = ['off', 'once', 'edit'];
    private const CUSTOMER_REPLIES = ['default', 'on', 'off'];
    private const STYLES = ['module', 'theme'];
    private const DEFAULTS = [
        'submit_label' => 'Submit',
        'success_message' => 'Thank you, your form has been sent.',
        'success_redirect' => '',
        'show_reference' => true,
        'store_submissions' => true,
        'notify_enabled' => false,
        'notify_recipients' => [],
        'notify_subject' => '',
        'notify_cc' => [],
        'notify_bcc' => [],
        'notify_routes' => [],
        'notify_when' => null,
        'autoresponder_enabled' => false,
        'autoresponder_email_field' => '',
        'email_field' => '',
        'access_login_required' => false,
        'access_groups' => [],
        'autoresponder_subject' => '',
        'autoresponder_body' => '',
        'autoresponder_when' => null,
        'draft_mode' => 'session',
        'store_ip' => false,
        'show_progress' => true,
        'seo_noindex' => false,
        'prev_label' => 'Back',
        'next_label' => 'Next',
        'page_title' => '',
        'meta_description' => '',
        'style' => 'module',
        'accent_color' => '',
        'survey_mode' => 'off',
        'guest_dedupe' => false,
        'account_edit' => false,
        'account_delete' => true,
        'customer_replies' => 'default',
        'open_from' => '',
        'close_at' => '',
        'schedule_timezone' => '',
        'closed_message' => 'This form is closed.',
        'not_open_message' => 'This form is not open yet.',
    ];
    private const BOOLEANS = [
        'show_reference', 'store_submissions', 'notify_enabled', 'autoresponder_enabled', 'store_ip', 'show_progress', 'seo_noindex',
        'access_login_required', 'guest_dedupe', 'account_edit', 'account_delete',
    ];
    private const EMAIL_LISTS = ['notify_recipients', 'notify_cc', 'notify_bcc'];
    private const LABELS = ['submit_label', 'prev_label', 'next_label'];
    private const MAX_ROUTES = 20;
    private const SUBJECTS = ['notify_subject', 'autoresponder_subject'];

    public function __construct(
        private readonly RedirectValidator $redirectValidator,
        private readonly Pool $fieldTypes,
        private readonly SettingValues $settingValues,
        private readonly ContributorPool $contributors,
        private readonly ConditionNormalizer $conditions
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $stored
     * @param string $defaultTimezone zone for the schedule when none is posted or stored (Model\Form\ScheduleTimezone)
     * @return array<string, mixed>
     * @throws InvalidSchemaException
     */
    public function normalize(array $raw, array $schema, array $stored = [], string $defaultTimezone = ''): array
    {
        /** @var string[] $errors */
        $errors = [];
        $out = self::DEFAULTS;

        foreach (self::BOOLEANS as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (self::LABELS as $key) {
            $out[$key] = $this->text($raw, $key, 50) ?: self::DEFAULTS[$key];
        }
        $out['success_message'] = $this->text($raw, 'success_message', 2000, true) ?: self::DEFAULTS['success_message'];
        $out['autoresponder_body'] = $this->text($raw, 'autoresponder_body', 5000, true);
        $out['page_title'] = $this->text($raw, 'page_title', 255);
        foreach (['closed_message', 'not_open_message'] as $key) {
            $out[$key] = $this->text($raw, $key, 500) ?: self::DEFAULTS[$key];
        }
        $out = array_merge($out, Schedule::normalize($raw, $stored, $defaultTimezone, $errors));
        $out['meta_description'] = $this->text($raw, 'meta_description', 500);

        foreach (self::SUBJECTS as $key) {
            $subject = $this->subject($raw[$key] ?? '', $this->label($key), $errors);
            $out[$key] = $subject;
        }
        foreach (self::EMAIL_LISTS as $key) {
            $out[$key] = $this->emails($raw[$key] ?? [], $this->label($key), $errors);
        }

        $sources = $this->routableFields($schema);
        $out['notify_routes'] = $this->routes($raw['notify_routes'] ?? [], $sources, $stored['notify_routes'] ?? [], $errors);
        foreach (['notify_when', 'autoresponder_when'] as $key) {
            $out[$key] = $this->conditions->normalizeRules(
                $raw[$key] ?? null,
                $sources,
                $this->conditions->flatten($stored[$key] ?? null),
                $this->label($key),
                $errors,
                false
            );
        }

        $redirect = $raw['success_redirect'] ?? '';
        if (!is_string($redirect) || !$this->redirectValidator->isAllowed(trim($redirect))) {
            $errors[] = (string)__('The success redirect URL must be a relative path or a URL on this store.');
        } else {
            $out['success_redirect'] = trim($redirect);
        }

        $draftMode = $raw['draft_mode'] ?? self::DEFAULTS['draft_mode'];
        if (!is_string($draftMode) || !in_array($draftMode, self::DRAFT_MODES, true)) {
            $errors[] = (string)__('The draft mode is invalid.');
        } else {
            $out['draft_mode'] = $draftMode;
        }

        $style = $raw['style'] ?? self::DEFAULTS['style'];
        if (!is_string($style) || !in_array($style, self::STYLES, true)) {
            $errors[] = (string)__('The form style is invalid.');
        } else {
            $out['style'] = $style;
        }

        $accent = $raw['accent_color'] ?? '';
        if (!is_string($accent) || preg_match('/^(?:#[0-9a-f]{6})?$/iD', trim($accent)) !== 1) {
            $errors[] = (string)__('The accent colour must be a hex colour such as #1d5fd1.');
        } else {
            $out['accent_color'] = strtolower(trim($accent));
        }

        $field = $raw['autoresponder_email_field'] ?? '';
        $out['autoresponder_email_field'] = is_string($field) ? trim($field) : '';
        if ($out['autoresponder_enabled'] && !in_array($out['autoresponder_email_field'], $this->emailFieldNames($schema), true)) {
            $errors[] = (string)__('The autoresponder needs an email field from the form.');
        }

        // Optional: a renamed or deleted field must not block saving the form.
        $emailField = is_string($raw['email_field'] ?? null) ? trim($raw['email_field']) : '';
        $out['email_field'] = in_array($emailField, $this->emailFieldNames($schema), true) ? $emailField : '';

        $survey = $raw['survey_mode'] ?? self::DEFAULTS['survey_mode'];
        if (!is_string($survey) || !in_array($survey, self::SURVEY_MODES, true)) {
            $errors[] = (string)__('The survey mode is invalid.');
        } elseif ($survey !== 'off' && !$out['store_submissions']) {
            $errors[] = (string)__('Survey mode needs the submissions to be stored.');
        } else {
            $out['survey_mode'] = $survey;
        }
        $replies = $raw['customer_replies'] ?? self::DEFAULTS['customer_replies'];
        if (!is_string($replies) || !in_array($replies, self::CUSTOMER_REPLIES, true)) {
            $errors[] = (string)__('The customer replies setting is invalid.');
        } else {
            $out['customer_replies'] = $replies;
        }
        $dedupeField = FormSettings::submitterEmailField($out);
        if ($out['guest_dedupe'] && !in_array($dedupeField, $this->emailFieldNames($schema), true)) {
            $errors[] = (string)__('Guest duplicate protection needs an email field from the form.');
        }

        $out['access_groups'] = $this->groups($raw['access_groups'] ?? [], $out['access_login_required']);

        $ext = $this->extensions($raw['ext'] ?? [], $stored['ext'] ?? [], $errors);
        if ($ext !== []) {
            $out['ext'] = $ext;
        }

        if ($errors) {
            throw new InvalidSchemaException($errors);
        }
        return $out;
    }

    /**
     * Stored routing the admin cannot edit (no submissions permission) must survive a schema change: rules
     * naming a field that is gone or no longer routable are dropped, and a route left without rules with them.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function pruneRouting(array $settings, array $schema): array
    {
        $sources = $this->routableFields($schema);
        if (array_key_exists('notify_routes', $settings)) {
            $routes = [];
            foreach (is_array($settings['notify_routes']) ? $settings['notify_routes'] : [] as $route) {
                $condition = is_array($route) ? $this->conditions->prune($route['condition'] ?? null, $sources) : null;
                if ($condition !== null) {
                    $route['condition'] = $condition;
                    $routes[] = $route;
                }
            }
            $settings['notify_routes'] = $routes;
        }
        if (array_key_exists('notify_when', $settings)) {
            $settings['notify_when'] = $this->conditions->prune($settings['notify_when'], $sources);
        }

        return $settings;
    }

    /**
     * A code whose contributor is no longer registered keeps its stored value; the posted one is ignored.
     *
     * @param string[] $errors
     * @return array<string, array<string, mixed>>
     */
    private function extensions(mixed $posted, mixed $stored, array &$errors): array
    {
        $posted = is_array($posted) ? $posted : [];
        $stored = is_array($stored) ? $stored : [];
        $ext = [];
        foreach ($this->contributors->all() as $code => $contributor) {
            $problems = [];
            $raw = is_array($posted[$code] ?? null) ? $posted[$code] : [];
            $spec = $contributor->getSpec();
            $ext[$code] = $this->settingValues->normalize($raw, $spec, $problems);
            foreach ($problems as [$kind, $key, $argument]) {
                $key = $spec[$key]['label'] ?? $key;
                $errors[] = match ($kind) {
                    SettingValues::PROBLEM_MIN_ITEMS => (string)__('Settings "%1": "%2" needs at least %3 entries.', $contributor->getLabel(), $key, $argument),
                    SettingValues::PROBLEM_MASK => (string)__('Settings "%1": "%2" is not a valid mask.', $contributor->getLabel(), $key),
                    default => (string)__('Settings "%1": "%2" must be a relative path or a URL on this store.', $contributor->getLabel(), $key),
                };
            }
        }
        foreach ($stored as $code => $values) {
            if (!$this->contributors->has((string)$code) && is_array($values)) {
                $ext[(string)$code] = $values;
            }
        }
        return $ext;
    }

    /**
     * @return int[]
     */
    private function groups(mixed $raw, bool $loginRequired): array
    {
        $groups = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            $valid = (is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id >= 0;
            // 32000 is the "ALL GROUPS" pseudo group; a required login rules out "not logged in"
            if ($valid && (int)$id !== GroupInterface::CUST_GROUP_ALL && !($loginRequired && (int)$id === 0)) {
                $groups[(int)$id] = (int)$id;
            }
        }
        sort($groups);

        return $groups;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function text(array $raw, string $key, int $max, bool $multiline = false): string
    {
        $value = $raw[$key] ?? '';
        if (!is_scalar($value)) {
            return '';
        }
        $value = strip_tags(str_replace("\r\n", "\n", (string)$value));
        $pattern = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        return mb_substr(trim(preg_replace($pattern, '', $value) ?? ''), 0, $max);
    }

    /**
     * @param string[] $errors
     */
    private function subject(mixed $value, string $label, array &$errors): string
    {
        if (!is_scalar($value)) {
            $value = '';
        }
        $value = trim(strip_tags((string)$value));
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || mb_strlen($value) > 255) {
            $errors[] = (string)__('The subject for "%1" must be a single line of at most 255 characters.', $label);
            return '';
        }
        return $value;
    }

    /** The builder's name for a setting, for error messages. */
    private function label(string $key): string
    {
        return (string)match ($key) {
            'notify_recipients' => __('Recipients'),
            'notify_cc' => __('CC'),
            'notify_bcc' => __('BCC'),
            'notify_subject', 'notify_when' => __('Notifications'),
            'autoresponder_subject', 'autoresponder_when' => __('Autoresponder'),
            'notify_routes' => __('Routing rules'),
            default => $key,
        };
    }

    /**
     * @param string[] $errors
     * @return string[]
     */
    private function emails(mixed $value, string $label, array &$errors): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\n]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            $errors[] = (string)__('"%1" must be a list of email addresses.', $label);
            return [];
        }
        $emails = [];
        foreach ($value as $item) {
            $email = is_string($item) ? trim($item) : '';
            if ($email === '' && is_string($item)) {
                continue;
            }
            if (preg_match('/[\x00-\x20\x7F]/', $email) === 1 || !SafeAddress::isValid($email)) {
                $errors[] = (string)__('"%1" contains an invalid email address.', $label);
                return [];
            }
            $emails[strtolower($email)] = $email;
        }
        if (count($emails) > self::MAX_EMAILS) {
            $errors[] = (string)__('"%1" can contain at most %2 email addresses.', $label, self::MAX_EMAILS);
            return [];
        }
        return array_values($emails);
    }

    /**
     * @param array<string, string> $sources routable field name => type code
     * @param string[] $errors
     * @return array<int, array{condition: array<string, mixed>, emails: string[], mode: string}>
     */
    private function routes(mixed $raw, array $sources, mixed $storedRoutes, array &$errors): array
    {
        if (!is_array($raw) || count($raw) > self::MAX_ROUTES) {
            $errors[] = (string)__('"%1" can contain at most %2 rules.', $this->label('notify_routes'), self::MAX_ROUTES);
            return [];
        }
        $storedRules = [];
        foreach (is_array($storedRoutes) ? $storedRoutes : [] as $stored) {
            array_push($storedRules, ...$this->conditions->flatten(is_array($stored) ? ($stored['condition'] ?? null) : null));
        }
        $routes = [];
        foreach ($raw as $route) {
            $route = is_array($route) ? $route : [];
            $mode = $route['mode'] ?? 'add';
            if (!in_array($mode, ['add', 'replace'], true)) {
                $errors[] = (string)__('A notification rule has an invalid mode.');
                continue;
            }
            $condition = $this->conditions->normalizeRules(
                $route['condition'] ?? null,
                $sources,
                $storedRules,
                $this->label('notify_routes'),
                $errors,
                false
            );
            if ($condition === null) {
                $errors[] = (string)__('A notification rule needs at least one valid condition.');
                continue;
            }
            $emails = $this->emails($route['emails'] ?? [], $this->label('notify_routes'), $errors);
            $routes[] = ['condition' => $condition, 'emails' => $emails, 'mode' => $mode];
        }
        return $routes;
    }

    /** A type that is not registered (module disabled) stays routable, so such a form can still be saved. */
    private function isRoutable(string $code): bool
    {
        if (!$this->fieldTypes->has($code)) {
            return true;
        }
        $type = $this->fieldTypes->get($code);
        return !$type->isFile() && !($type instanceof RoutableTypeInterface && !$type->canBeRouted());
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, string> field name => type code
     */
    private function routableFields(array $schema): array
    {
        $fields = [];
        foreach (SchemaFields::all($schema) as $item) {
            if (isset($item['name']) && $this->isRoutable((string)($item['type'] ?? ''))) {
                $fields[(string)$item['name']] = (string)($item['type'] ?? '');
            }
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $schema
     * @return string[]
     */
    private function emailFieldNames(array $schema): array
    {
        $names = [];
        foreach (SchemaFields::all($schema) as $item) {
            if ($this->fieldTypes->isEmailAddress((string)($item['type'] ?? '')) && isset($item['name'])) {
                $names[] = (string)$item['name'];
            }
        }
        return $names;
    }
}
