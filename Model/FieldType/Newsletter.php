<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AvailabilityInterface;
use Furan\FormBuilder\Api\FieldType\ConsentTypeInterface;
use Furan\FormBuilder\Model\Newsletter\NewsletterBridge;

class Newsletter extends Checkbox implements AvailabilityInterface, ConsentTypeInterface
{
    public const CHECKED_VALUE = 'yes';

    public function __construct(private readonly NewsletterBridge $bridge)
    {
    }

    public function getCode(): string
    {
        return 'newsletter';
    }

    public function getLabel(): string
    {
        return (string)__('Newsletter');
    }

    public function getSettingsSpec(): array
    {
        return ['email_field' => ['type' => 'string', 'default' => '', 'max_length' => 64]];
    }

    public function getBuilderDefaults(): array
    {
        return ['label' => (string)__('Sign me up for the newsletter'), 'draft' => false];
    }

    public function isAvailable(?int $storeId = null): bool
    {
        return $this->bridge->isAvailable($storeId);
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function getCheckedValue(array $field): string
    {
        return self::CHECKED_VALUE;
    }
}
