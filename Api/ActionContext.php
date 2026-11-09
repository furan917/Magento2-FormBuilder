<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/**
 * What an action is told about the run. $attempt is 1 on the first run and counts up on retries.
 *
 * @api
 */
class ActionContext
{
    public function __construct(
        public readonly bool $isEdit = false,
        public readonly int $storeId = 0,
        public readonly ?int $customerId = null,
        public readonly int $attempt = 1
    ) {
    }
}
