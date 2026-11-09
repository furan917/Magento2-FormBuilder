<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Privacy;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::privacy';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::privacy');
        $page->getConfig()->getTitle()->prepend((string)__('Privacy: export or erase personal data'));
        return $page;
    }
}
