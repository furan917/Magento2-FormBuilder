<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;

class NewAction extends AbstractForm implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::forms_save';

    public function __construct(Context $context, private readonly ForwardFactory $forwardFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->forwardFactory->create()->forward('edit');
    }
}
