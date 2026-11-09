<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Model\Validation\Patterns;

class Url extends AbstractPatternType
{
    public function getCode(): string
    {
        return 'url';
    }

    public function getLabel(): string
    {
        return (string)__('URL');
    }

    public function getAllowedRules(): array
    {
        return ['max_length'];
    }
    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please enter a valid URL starting with http:// or https://.');
    }

    protected function getPattern(): string
    {
        return Patterns::URL;
    }}
