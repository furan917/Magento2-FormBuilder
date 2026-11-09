<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Privacy;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Confirmation step before an erase: the page lists what matches (Block\Adminhtml\Privacy). POST keeps the email
 * out of URLs and logs.
 */
class Preview extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::privacy';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $customerId = (int)$this->getRequest()->getParam('customer_id');
        $email = trim((string)$this->getRequest()->getParam('email'));
        if ($customerId <= 0 && $email === '') {
            $this->messageManager->addErrorMessage(__('Enter a customer ID or an email address.'));
            return $this->redirectResult()->setPath('*/*/');
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::privacy');
        $page->getConfig()->getTitle()->prepend((string)__('Privacy: confirm erase'));
        return $page;
    }
}
