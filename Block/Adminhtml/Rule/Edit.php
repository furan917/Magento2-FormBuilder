<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Rule;

use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\Validation\BuiltInRules;
use Furan\FormBuilder\Model\Validation\SavedRuleManager;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Edit extends Template
{
    /** @var array{label: string, code: string, pattern: string, message: string, example: string}|null */
    private ?array $values = null;
    /** @var array<int, array{id: int, title: string}>|null */
    private ?array $usage = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly SavedRuleManager $manager,
        private readonly BuiltInRules $builtIn,
        private readonly DataPersistorInterface $dataPersistor,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRuleId(): int
    {
        return (int)$this->getRequest()->getParam('id');
    }

    public function isNew(): bool
    {
        return $this->getRuleId() <= 0;
    }

    /**
     * Form values: a failed save's input, else the stored rule, else a copied built-in rule, else blanks.
     *
     * @return array{label: string, code: string, pattern: string, message: string, example: string}
     */
    public function getValues(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        $values = ['label' => '', 'code' => '', 'pattern' => '', 'message' => '', 'example' => ''];
        if (!$this->isNew()) {
            $values = array_intersect_key($this->storedData(), $values) + $values;
        } else {
            $from = (string)$this->getRequest()->getParam('from');
            $source = $this->builtIn->all()[$from] ?? null;
            if ($source !== null) {
                $values = [
                    'label' => (string)__('%1 (custom)', __($source['label'])),
                    'code' => $from . '_custom',
                    'pattern' => $source['pattern'],
                    'message' => (string)__($source['message']),
                    'example' => $source['example'],
                ];
            }
        }
        $persisted = $this->dataPersistor->get(PersistKeys::RULE);
        if (is_array($persisted)) {
            $this->dataPersistor->clear(PersistKeys::RULE);
            $values = array_intersect_key($persisted, $values) + $values;
        }
        return $this->values = [
            'label' => (string)$values['label'],
            'code' => (string)$values['code'],
            'pattern' => (string)$values['pattern'],
            'message' => (string)$values['message'],
            'example' => (string)$values['example'],
        ];
    }

    /** @return array<string, mixed> */
    private function storedData(): array
    {
        try {
            return (array)$this->manager->get($this->getRuleId())->getData();
        } catch (NoSuchEntityException) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    public function getUsage(): array
    {
        $this->usage ??= $this->isNew() ? [] : $this->manager->usage($this->getValues()['code']);
        return $this->usage;
    }

    public function getFormEditUrl(int $formId): string
    {
        return $this->_authorization->isAllowed('Furan_FormBuilder::forms')
            ? $this->getUrl('*/form/edit', ['id' => $formId])
            : '';
    }

    public function canSave(): bool
    {
        return $this->_authorization->isAllowed('Furan_FormBuilder::rules_save');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('*/*/save');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('*/*/delete', ['id' => $this->getRuleId()]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/');
    }
}
