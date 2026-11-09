<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition;

use Furan\FormBuilder\Api\ConditionOperatorInterface;
use Psr\Log\LoggerInterface;

class OperatorPool
{
    /** @var array<string, ConditionOperatorInterface> */
    private readonly array $operators;

    /**
     * @param ConditionOperatorInterface[] $operators keyed by operator code
     */
    public function __construct(LoggerInterface $logger, array $operators = [])
    {
        $valid = [];
        foreach ($operators as $key => $operator) {
            if (!$operator instanceof ConditionOperatorInterface || $operator->getCode() !== (string)$key) {
                $logger->warning(sprintf('Condition operator pool item "%s" skipped: it does not match its code.', $key));
                continue;
            }
            $valid[$key] = $operator;
        }
        $this->operators = $valid;
    }

    public function get(string $code): ConditionOperatorInterface
    {
        if (!isset($this->operators[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown condition operator "%s".', $code));
        }
        return $this->operators[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->operators[$code]);
    }

    /**
     * @return array<string, ConditionOperatorInterface>
     */
    public function all(): array
    {
        return $this->operators;
    }
}
