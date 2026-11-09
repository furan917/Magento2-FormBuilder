<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Customer;

use Magento\Customer\Model\Session;

/**
 * Whether an admin is browsing as the customer ("Login as Customer"). Reads the session key core sets
 * when impersonating (typo "admind" is core's), so nothing depends on that module being installed.
 */
class Impersonation
{
    private const ADMIN_ID_KEY = 'logged_as_customer_admind_id';

    public function __construct(private readonly Session $customerSession)
    {
    }

    public function isActive(): bool
    {
        return (int)$this->customerSession->getData(self::ADMIN_ID_KEY) > 0;
    }

    public function accountRefusal(): string
    {
        return (string)__('This is not available while you are logged in as this customer.');
    }

    public function refusal(): string
    {
        return (string)__('Survey answers cannot be submitted or changed while you are logged in as this customer.');
    }
}
