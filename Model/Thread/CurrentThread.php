<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

/**
 * Request-scoped hand-over of the verified guest thread from the controller to the block.
 *
 * @phpstan-import-type ThreadContext from ThreadAccess
 */
class CurrentThread
{
    /** @var ThreadContext|null */
    private ?array $context = null;

    /** @param ThreadContext|null $context */
    public function set(?array $context): void
    {
        $this->context = $context;
    }

    /** @return ThreadContext|null */
    public function get(): ?array
    {
        return $this->context;
    }
}
