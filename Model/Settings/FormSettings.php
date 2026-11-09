<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Settings;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SubmissionInput;

class FormSettings
{
    public function __construct(private readonly ContributorPool $contributors)
    {
    }

    /**
     * Stored values of one contributor; spec defaults fill keys a form saved earlier does not have.
     *
     * @return array<string, mixed>
     */
    public function extension(FormInterface $form, string $code): array
    {
        $stored = $form->getSettings()['ext'][$code] ?? [];
        $stored = is_array($stored) ? $stored : [];
        if (!$this->contributors->has($code)) {
            return $stored;
        }
        $defaults = [];
        foreach ($this->contributors->all()[$code]->getSpec() as $key => $spec) {
            $defaults[$key] = $spec['default'] ?? null;
        }
        return array_intersect_key($stored, $defaults) + $defaults;
    }

    /**
     * Name of the field that holds the submitter's email: the form's email field, else the autoresponder's.
     *
     * @param array<string, mixed> $settings
     */
    public static function submitterEmailField(array $settings): string
    {
        return (string)(($settings['email_field'] ?? '') ?: ($settings['autoresponder_email_field'] ?? ''));
    }

    /**
     * The logged-in customer's email, else the answer in the submitter email field.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $values
     */
    public static function submitterEmail(SubmissionInput $input, array $settings, array $values): string
    {
        if ($input->customerEmail !== '') {
            return $input->customerEmail;
        }
        $answer = $values[self::submitterEmailField($settings)] ?? '';

        return is_string($answer) ? $answer : '';
    }
}
