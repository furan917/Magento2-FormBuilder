<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Action;

use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Api\SubmissionActionInterface;

class SubmissionActionPool
{
    /** @var array<string, SubmissionActionInterface> */
    private readonly array $actions;

    /**
     * @param array<array-key, mixed> $actions registered in di.xml; items that are not actions are skipped
     */
    public function __construct(array $actions = [])
    {
        $valid = [];
        foreach ($actions as $key => $action) {
            if ($action instanceof SubmissionActionInterface) {
                $valid[$key] = $action;
            }
        }
        $this->actions = $valid;
    }

    /**
     * @return array<string, SubmissionActionInterface> by pool key
     */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * @return array<string, DeferrableActionInterface> by pool key
     */
    public function deferrable(): array
    {
        return array_filter(
            $this->actions,
            static fn (SubmissionActionInterface $action): bool => $action instanceof DeferrableActionInterface
        );
    }
}
