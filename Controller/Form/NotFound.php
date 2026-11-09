<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Form;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class NotFound implements HttpGetActionInterface
{
    public function __construct(private readonly PageFactory $pageFactory)
    {
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setHttpResponseCode(404);
        $page->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $page->getConfig()->getTitle()->set((string)__('Form not found'));

        return $page;
    }
}
