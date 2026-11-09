<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Analytics;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Adminhtml\Analytics;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Stats\Folder;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::analytics';

    private const FOLD_BATCHES = 2;

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Folder $folder,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $formId = (int)$this->getRequest()->getParam('form_id');
        try {
            $form = $this->formRepository->getById($formId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectResult()->setPath('furanform/form/');
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::form_list');
        $page->getConfig()->getTitle()->prepend((string)__('Analytics: %1', $form->getTitle()));
        if ($this->config->isAnalyticsEnabled()) {
            $pending = $this->folder->foldForm($formId, self::FOLD_BATCHES);
            $block = $page->getLayout()->getBlock('furan_formbuilder_analytics');
            if ($block instanceof Analytics) {
                $block->setData('pending', $pending);
            }
        }
        return $page;
    }
}
