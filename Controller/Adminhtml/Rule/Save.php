<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Rule;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\Validation\SavedRuleManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;

class Save extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::rules_save';

    public function __construct(
        Context $context,
        private readonly SavedRuleManager $manager,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $id = (int)$request->getParam('id') ?: null;
        $data = [];
        foreach (['label', 'code', 'pattern', 'message', 'example'] as $key) {
            $data[$key] = (string)$request->getParam($key, '');
        }
        $redirect = $this->redirectResult();
        try {
            $rule = $this->manager->save($data, $id);
            $this->dataPersistor->clear(PersistKeys::RULE);
            $this->messageManager->addSuccessMessage(__('The rule "%1" has been saved.', $rule->getData('label')));
            return $request->getParam('back')
                ? $redirect->setPath('*/*/edit', ['id' => (int)$rule->getId()])
                : $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            foreach (explode("\n", $e->getMessage()) as $line) {
                $this->messageManager->addErrorMessage($line);
            }
            $this->dataPersistor->set(PersistKeys::RULE, $data);
            return $redirect->setPath('*/*/edit', $id ? ['id' => $id] : []);
        }
    }
}
