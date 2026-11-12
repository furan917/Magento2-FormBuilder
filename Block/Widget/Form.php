<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Widget;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Form as FormBlock;
use Furan\FormBuilder\Block\ItemContext;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Directory\CountryOptions;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Storefront\ConfigBuilder;
use Furan\FormBuilder\Model\Theme\HyvaDetector;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template\Context;
use Magento\Widget\Block\BlockInterface;
use Psr\Log\LoggerInterface;

class Form extends FormBlock implements BlockInterface
{
    private bool $formLoaded = false;
    private ?FormInterface $widgetForm = null;

    /**
     * @param array<string, string|array<string, mixed>> $scripts
     * @param array<string, string|array<string, mixed>> $styles
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        ConfigBuilder $configBuilder,
        Pool $fieldTypes,
        Config $moduleConfig,
        LoggerInterface $logger,
        MiniMarkdown $markdown,
        ScheduleGate $schedule,
        CountryOptions $countries,
        JsonHexTag $jsonHexTag,
        HyvaDetector $hyvaDetector,
        ItemContext $itemContext,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Translator $translator,
        array $scripts = [],
        array $styles = [],
        array $data = []
    ) {
        parent::__construct($context, $configBuilder, $fieldTypes, $moduleConfig, $logger, $markdown, $schedule, $countries, $jsonHexTag, $hyvaDetector, $itemContext, $scripts, $styles, $data);
    }

    public function getForm(): ?FormInterface
    {
        if (!$this->formLoaded) {
            $this->formLoaded = true;
            $formId = (int)$this->getData('form_id');
            if ($formId > 0) {
                try {
                    $storeId = (int)$this->_storeManager->getStore()->getId();
                    $this->widgetForm = $this->translator->localize(
                        $this->formRepository->getActiveById($formId, $storeId),
                        $storeId
                    );
                } catch (NoSuchEntityException) {
                    $this->widgetForm = null;
                }
            }
        }
        return $this->widgetForm;
    }

    public function getIdentities(): array
    {
        $formId = (int)$this->getData('form_id');
        return $formId > 0 ? ['furan_form_' . $formId] : [];
    }

    protected function _construct()
    {
        parent::_construct();
        if (!$this->hasData('template')) {
            $this->setTemplate('Furan_FormBuilder::form.phtml');
        }
    }
}
