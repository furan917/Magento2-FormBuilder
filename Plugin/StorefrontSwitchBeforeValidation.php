<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Plugin;

use Furan\FormBuilder\Observer\StorefrontSwitch;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\ValidatorInterface;
use Magento\Framework\App\RequestInterface;

/** Request validation (form key) runs before predispatch, so the kill switch also has to answer here with a 404. */
class StorefrontSwitchBeforeValidation
{
    public function __construct(private readonly StorefrontSwitch $switch)
    {
    }

    /** @return array<int, mixed>|null */
    public function beforeValidate(ValidatorInterface $subject, RequestInterface $request, ActionInterface $action): ?array
    {
        $this->switch->assertOpen($request);
        return null;
    }
}
