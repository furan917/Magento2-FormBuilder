<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\CacheContextFactory;
use Magento\Ui\Component\MassAction\Filter;

class MassStatus extends AbstractForm implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::forms_save';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly FormResource $formResource,
        private readonly CacheContextFactory $cacheContextFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $isActive = (bool)(int)$this->getRequest()->getParam('status');
        try {
            $ids = array_map('intval', $this->filter->getCollection($this->collectionFactory->create())->getAllIds());
            $count = 0;
            if ($ids) {
                $count = $this->formResource->setActive($ids, $isActive);
                $context = $this->cacheContextFactory->create();
                $context->registerEntities(Form::CACHE_TAG, $ids);
                $this->_eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
            }
            $this->messageManager->addSuccessMessage(__('A total of %1 form(s) have been updated.', $count));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->redirectResult()->setPath('*/*/');
    }
}
