<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\EmailAddressTypeInterface;
use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Model\Validation\Patterns;

class Email extends AbstractPatternType implements PersonalByDefaultInterface, EmailAddressTypeInterface
{
    public function getCode(): string
    {
        return 'email';
    }

    public function isPersonalByDefault(): bool
    {
        return true;
    }

    public function getLabel(): string
    {
        return (string)__('Email address');
    }

    public function getAllowedRules(): array
    {
        return ['max_length', 'matches', 'pattern', 'preset'];
    }
    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please enter a valid email address.');
    }

    protected function getPattern(): string
    {
        return Patterns::EMAIL;
    }}
