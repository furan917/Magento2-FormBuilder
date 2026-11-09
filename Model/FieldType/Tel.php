<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Model\Validation\Patterns;

class Tel extends AbstractPatternType implements PersonalByDefaultInterface
{
    public function getCode(): string
    {
        return 'tel';
    }

    public function isPersonalByDefault(): bool
    {
        return true;
    }

    public function getLabel(): string
    {
        return (string)__('Phone number');
    }

    public function getAllowedRules(): array
    {
        return ['min_length', 'max_length', 'pattern', 'preset'];
    }
    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please enter a valid phone number.');
    }

    public function getSettingsSpec(): array
    {
        return ['mask' => $this->maskSpec()];
    }

    protected function getPattern(): string
    {
        return Patterns::PHONE;
    }}
