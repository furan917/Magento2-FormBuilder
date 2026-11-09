<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Psr\Log\LoggerInterface;

class ActionPool
{
    /** @var array<string, ConditionActionInterface> */
    private readonly array $actions;

    /**
     * @param ConditionActionInterface[] $actions keyed by action code
     */
    public function __construct(LoggerInterface $logger, array $actions = [])
    {
        $valid = [];
        foreach ($actions as $key => $action) {
            if (!$action instanceof ConditionActionInterface || $action->getCode() !== (string)$key) {
                $logger->warning(sprintf('Condition action pool item "%s" skipped: it does not match its code.', $key));
                continue;
            }
            $valid[$key] = $action;
        }
        $this->actions = $valid;
    }

    public function get(string $code): ConditionActionInterface
    {
        if (!isset($this->actions[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown condition action "%s".', $code));
        }
        return $this->actions[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->actions[$code]);
    }

    /**
     * @return array<string, ConditionActionInterface>
     */
    public function all(): array
    {
        return $this->actions;
    }
}
