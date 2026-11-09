<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Action;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Furan\FormBuilder\Api\Data\FormState;

class HideOptions implements ConditionActionInterface
{
    public function getCode(): string
    {
        return 'hide_options';
    }

    public function getLabel(): string
    {
        return (string)__('Hide options');
    }

    public function targets(): array
    {
        return ['field'];
    }

    public function needsParam(): ?string
    {
        return self::PARAM_OPTIONS;
    }

    public function apply(FormState $state, string $targetId, mixed $param, bool $matched): void
    {
        if ($matched && is_array($param)) {
            $state->hideOptions(
                $targetId,
                array_values(array_filter($param, is_string(...)))
            );
        }
    }
}
