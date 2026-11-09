<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Action;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Furan\FormBuilder\Api\Data\FormState;

class RequireField implements ConditionActionInterface
{
    public function getCode(): string
    {
        return 'require';
    }

    public function getLabel(): string
    {
        return (string)__('Require');
    }

    public function targets(): array
    {
        return ['field'];
    }

    public function needsParam(): ?string
    {
        return null;
    }

    public function apply(FormState $state, string $targetId, mixed $param, bool $matched): void
    {
        if ($matched) {
            $state->require($targetId);
        }
    }
}
