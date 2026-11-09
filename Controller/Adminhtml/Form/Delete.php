<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Form\Purger;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

class Delete extends AbstractForm implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::forms_delete';

    public function __construct(
        Context $context,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Purger $purger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $id = (int)$this->getRequest()->getParam('id');
            if (!$this->_authorization->isAllowed('Furan_FormBuilder::submissions_delete') && $this->purger->hasSubmissions($id)) {
                $this->messageManager->addErrorMessage(__(
                    'The form "%1" has submissions. You need permission to delete submissions to delete it.',
                    $this->formRepository->getById($id)->getTitle()
                ));
                return $this->redirectResult()->setPath('*/*/');
            }
            if ($this->purger->deleteOrSchedule($this->formRepository->getById($id))) {
                $this->messageManager->addSuccessMessage(__('The form has been deleted.'));
            } else {
                $this->messageManager->addSuccessMessage(
                    __('The form has been disabled and its submissions are being deleted in the background.')
                );
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->redirectResult()->setPath('*/*/');
    }
}
