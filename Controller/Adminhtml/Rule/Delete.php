<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Rule;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Validation\SavedRuleManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

class Delete extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::rules_save';

    public function __construct(Context $context, private readonly SavedRuleManager $manager)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->redirectResult();
        try {
            $this->manager->delete($id);
            $this->messageManager->addSuccessMessage(__('The rule has been deleted.'));
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/edit', ['id' => $id]);
        }
    }
}
