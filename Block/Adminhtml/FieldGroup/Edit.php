<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\FieldGroup;

use Furan\FormBuilder\Model\Admin\BuilderSharedConfig;
use Furan\FormBuilder\ViewModel\FieldGroupEditor;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\Serializer\JsonHexTag;

/**
 * The form builder in group mode: one page of items, no form settings, saved to the group.
 */
class Edit extends Template
{
    /** @var array<string, mixed>|null */
    private ?array $groupConfig = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly BuilderSharedConfig $sharedConfig,
        private readonly JsonHexTag $jsonHexTag,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEditor(): FieldGroupEditor
    {
        $editor = $this->getData('editor');
        if (!$editor instanceof FieldGroupEditor) {
            throw new \LogicException('The field group page needs the editor view model.');
        }
        return $editor;
    }

    /** @return array<string, mixed> */
    public function getBuilderConfig(): array
    {
        if ($this->groupConfig === null) {
            $editor = $this->getEditor();
            $this->groupConfig = ['form' => $editor->getForm(), 'group' => ['usage' => $editor->getUsage()]] + $this->sharedConfig->get() + [
                'settingsContributors' => [],
                'urls' => [
                    'save' => $editor->getSaveUrl(),
                    'back' => $editor->getBackUrl(),
                    'delete' => $editor->canDelete() ? $editor->getDeleteUrl() : '',
                ],
                'fieldGroups' => $editor->getFieldGroupUrls(),
                'permissions' => ['save' => $editor->canEdit(), 'notifications' => false],
                'errors' => [],
            ];
        }
        return $this->groupConfig;
    }

    public function getConfigJson(): string
    {
        return $this->jsonHexTag->serialize($this->getBuilderConfig());
    }
}
