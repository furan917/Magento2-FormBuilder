<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Action;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Furan\FormBuilder\Api\Data\FormState;

class Hide implements ConditionActionInterface
{
    public function getCode(): string
    {
        return 'hide';
    }

    public function getLabel(): string
    {
        return (string)__('Hide');
    }

    public function targets(): array
    {
        return ['page', 'section', 'field', 'content'];
    }

    public function needsParam(): ?string
    {
        return null;
    }

    public function apply(FormState $state, string $targetId, mixed $param, bool $matched): void
    {
        if ($matched) {
            $state->hide($targetId);
        }
    }
}
