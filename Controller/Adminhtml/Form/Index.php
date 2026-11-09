<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends AbstractForm implements HttpGetActionInterface
{
    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::form_list');
        $page->getConfig()->getTitle()->prepend((string)__('Forms'));
        return $page;
    }
}
