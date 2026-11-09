<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Furan\FormBuilder\Model\Form\Purger;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

class MassDelete extends AbstractForm implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::forms_delete';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly Purger $purger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $count = 0;
            $scheduled = 0;
            $canDeleteSubmissions = $this->_authorization->isAllowed('Furan_FormBuilder::submissions_delete');
            foreach ($collection as $form) {
                if (!$canDeleteSubmissions && $this->purger->hasSubmissions((int)$form->getId())) {
                    $this->messageManager->addErrorMessage(__(
                        'The form "%1" has submissions. You need permission to delete submissions to delete it.',
                        $form->getTitle()
                    ));
                    continue;
                }
                if ($this->purger->deleteOrSchedule($form)) {
                    $count++;
                } else {
                    $scheduled++;
                }
            }
            $this->messageManager->addSuccessMessage(__('A total of %1 form(s) have been deleted.', $count));
            if ($scheduled > 0) {
                $this->messageManager->addNoticeMessage(__(
                    '%1 large form(s) have been disabled and are being deleted in the background.',
                    $scheduled
                ));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->redirectResult()->setPath('*/*/');
    }
}
