<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml;

use Furan\FormBuilder\Model\Privacy\Eraser;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Privacy extends Template
{
    /** @var array{field: string, value: string, total: int, accounts: int, forms: array<int, array{form_id: int, title: string, count: int}>}|null */
    private ?array $preview = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly Eraser $eraser,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * What the posted lookup would erase; null outside the preview action.
     *
     * @return array{field: string, value: string, total: int, accounts: int, forms: array<int, array{form_id: int, title: string, count: int}>}|null
     */
    public function getErasePreview(): ?array
    {
        if ($this->getRequest()->getActionName() !== 'preview') {
            return null;
        }
        if ($this->preview === null) {
            // Same precedence as the erase action: a customer ID wins over an email.
            $customerId = (int)$this->getRequest()->getParam('customer_id');
            $email = trim((string)$this->getRequest()->getParam('email'));
            $lookup = $customerId > 0 ? ['field' => 'customer_id', 'value' => (string)$customerId]
                : ['field' => 'email', 'value' => $email];
            $this->preview = $lookup + $this->eraser->preview(max(0, $customerId), $customerId > 0 ? '' : $email);
        }
        return $this->preview;
    }
}
