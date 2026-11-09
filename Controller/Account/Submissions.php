<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/** Action names must stay clear of the Customer plugin's allowedActions (create, login, confirm, ...). */
class Submissions implements AccountInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly Session $session
    ) {
    }

    /** @return Page|Forward */
    public function execute()
    {
        if ((int)$this->session->getCustomerId() <= 0) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        return $this->pageFactory->create();
    }
}
