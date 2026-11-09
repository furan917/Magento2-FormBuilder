<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

use Furan\FormBuilder\Api\ValidationRuleInterface;

class RulePool
{
    /**
     * @param ValidationRuleInterface[] $rules keyed by rule code
     */
    public function __construct(private readonly array $rules = [])
    {
    }

    public function get(string $code): ValidationRuleInterface
    {
        if (!isset($this->rules[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown validation rule "%s".', $code));
        }
        return $this->rules[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->rules[$code]);
    }

    /**
     * @return ValidationRuleInterface[]
     */
    public function all(): array
    {
        return $this->rules;
    }
}
