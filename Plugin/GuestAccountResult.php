<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Plugin;

use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\ResponseInterface;

/**
 * The customer account plugin returns nothing for guests after setting the login redirect; core controllers
 * extend Action, which turns that into the response. Ours implement the action interfaces directly.
 */
class GuestAccountResult
{
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function afterExecute(AccountInterface $subject, mixed $result): mixed
    {
        return $result ?? $this->response;
    }
}
