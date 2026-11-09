<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Model\Account\SubmissionReader;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/** Unknown and foreign ids both give 404 so ids cannot be probed. */
class Submission implements AccountInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly Session $session,
        private readonly RequestInterface $request,
        private readonly SubmissionReader $reader
    ) {
    }

    /** @return Page|Forward */
    public function execute()
    {
        $customerId = (int)$this->session->getCustomerId();
        $row = $customerId > 0 ? $this->reader->find($customerId, (int)$this->request->getParam('id')) : null;
        if ($row === null) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        return $this->pageFactory->create();
    }
}
