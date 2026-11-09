<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\FieldGroup;

use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\FieldGroup\BuilderGroups;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Saves an existing group from its builder page. New groups only come from "Save as group" in a form.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::field_groups_edit';

    public function __construct(
        Context $context,
        private readonly FieldGroupManager $manager,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly Json $json
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $id = (int)$request->getParam('id');
        $typed = [];
        foreach (['name', 'description', 'fields', 'translations'] as $key) {
            $value = $request->getParam($key);
            if (is_string($value)) {
                $typed[$key] = $value;
            }
        }
        $typed += ['name' => '', 'description' => ''];
        $redirect = $this->resultRedirectFactory->create();
        if ($id <= 0) {
            $this->messageManager->addErrorMessage(__('Field groups are created with "Save as group" in the form builder.'));
            return $redirect->setPath('*/*/');
        }
        try {
            $group = $this->manager->save($this->content($typed), $id);
            $this->dataPersistor->clear(PersistKeys::FIELD_GROUP);
            $this->messageManager->addSuccessMessage(__('The field group "%1" has been saved.', $group->getData('name')));
            return $request->getParam('back')
                ? $redirect->setPath('*/*/edit', ['id' => $id])
                : $redirect->setPath('*/*/');
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            foreach (explode("\n", $e->getMessage()) as $line) {
                $this->messageManager->addErrorMessage($line);
            }
            $this->dataPersistor->set(PersistKeys::FIELD_GROUP, $typed);
            return $redirect->setPath('*/*/edit', ['id' => $id]);
        }
    }

    /**
     * @param array<string, string> $typed
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function content(array $typed): array
    {
        $data = ['name' => $typed['name'], 'description' => $typed['description']];
        if (!isset($typed['fields'])) {
            return $data;
        }
        $data['fields'] = $this->decode($typed['fields'], true);
        $data['translations'] = $this->decode($typed['translations'] ?? '{}', false);
        return $data;
    }

    /**
     * @return array<mixed>
     * @throws LocalizedException
     */
    private function decode(string $raw, bool $list): array
    {
        if (strlen($raw) > BuilderGroups::MAX_PAYLOAD_BYTES) {
            throw new LocalizedException(__('This group is too large to save.'));
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            $decoded = null;
        }
        if (!is_array($decoded) || ($list && !array_is_list($decoded))) {
            throw new LocalizedException(__('The group could not be read.'));
        }
        return $decoded;
    }
}
