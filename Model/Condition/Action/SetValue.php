<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Action;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Furan\FormBuilder\Api\Data\FormState;

class SetValue implements ConditionActionInterface
{
    public function getCode(): string
    {
        return 'set_value';
    }

    public function getLabel(): string
    {
        return (string)__('Set value');
    }

    public function targets(): array
    {
        return ['field'];
    }

    public function needsParam(): ?string
    {
        return self::PARAM_VALUE;
    }

    public function apply(FormState $state, string $targetId, mixed $param, bool $matched): void
    {
        if ($matched && is_scalar($param)) {
            $state->setValue($targetId, (string)$param);
        }
    }
}
