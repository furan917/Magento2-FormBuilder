<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Magento\Framework\AuthorizationInterface;

/**
 * What the caller of a form write is allowed to change, and who they are.
 *
 * @api
 */
class FormWriteContext
{
    /** @param \Closure(string): bool $isAllowed ACL decision per resource */
    public function __construct(private readonly \Closure $isAllowed, public readonly ?int $adminId = null)
    {
    }

    /** Follows the admin's ACL; use this for any code that acts for a logged-in admin. */
    public static function forAdmin(AuthorizationInterface $authorization, ?int $adminId): self
    {
        return new self($authorization->isAllowed(...), $adminId);
    }

    /** Skips every ACL check. For CLI and other trusted callers only, never for a request path. */
    public static function allowAll(): self
    {
        return new self(static fn (): bool => true);
    }

    public function isAllowed(string $resource): bool
    {
        return ($this->isAllowed)($resource);
    }
}
