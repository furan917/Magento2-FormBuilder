<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FieldType\RenderHintsInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Directory\CountryOptions;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\Schema\ContentTypes;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Storefront\ConfigBuilder;
use Furan\FormBuilder\Model\Theme\HyvaDetector;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Form extends Template implements IdentityInterface
{
    private const DEFAULT_TEMPLATE = 'Furan_FormBuilder::form.phtml';
    private const CLOSED_TEMPLATE = 'Furan_FormBuilder::form_closed.phtml';

    /** @var ?\WeakMap<object, \stdClass> per-layout (i.e. per page) render state */
    private static ?\WeakMap $layoutState = null;

    private ?FormInterface $form = null;
    /** @var array<string, mixed>|null */
    private ?array $config = null;
    private ?int $instanceNumber = null;
    /** @var array<string, mixed>|null */
    private ?array $renderSchema = null;
    /** @var array<int, array{value: string, label: string}>|null */
    private ?array $countryOptions = null;

    /**
     * @param array<string, string|array<string, mixed>> $scripts
     * @param array<string, string|array<string, mixed>> $styles
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ConfigBuilder $configBuilder,
        private readonly Pool $fieldTypes,
        private readonly Config $moduleConfig,
        private readonly LoggerInterface $logger,
        private readonly MiniMarkdown $markdown,
        private readonly ScheduleGate $schedule,
        private readonly CountryOptions $countries,
        private readonly JsonHexTag $jsonHexTag,
        private readonly HyvaDetector $hyvaDetector,
        private readonly ItemContext $itemContext,
        private readonly array $scripts = [],
        private readonly array $styles = [],
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function setForm(FormInterface $form): static
    {
        $this->form = $form;
        $this->config = null;
        $this->renderSchema = null;
        $this->countryOptions = null;
        return $this;
    }

    /**
     * The form schema without fields whose type is unavailable for the rendering store view.
     *
     * @return array<string, mixed>
     */
    public function getRenderSchema(): array
    {
        return $this->renderSchema ??= $this->withoutUnavailable($this->getForm()?->getSchema() ?? [], $this->getStoreId());
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withoutUnavailable(array $schema, int $storeId): array
    {
        return SchemaFields::filter($schema, function (array $field) use ($storeId): bool {
            $code = (string)($field['type'] ?? '');
            return !$this->fieldTypes->has($code) || $this->fieldTypes->isUsable($code, $storeId);
        });
    }

    public function getForm(): ?FormInterface
    {
        return $this->form;
    }

    /** On a form's own page a lone page titled like the page heading would just repeat it; widgets keep it. */
    public function repeatsPageHeading(string $pageTitle): bool
    {
        $heading = trim((string)$this->getData('page_heading'));
        $pages = (array)($this->getForm()?->getSchema()['pages'] ?? []);

        return $heading !== '' && count($pages) === 1 && mb_strtolower($heading) === mb_strtolower(trim($pageTitle));
    }

    /** The rendering store view's locale code (en_GB); the review step formats dates with it. */
    public function getLocale(): string
    {
        $code = (string)$this->_scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $this->getStoreId());

        return preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/D', $code) === 1 ? $code : '';
    }

    public function getIdentities(): array
    {
        $form = $this->getForm();
        return $form === null ? [] : ['furan_form_' . $form->getId()];
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        $form = $this->getForm();

        return $form === null ? [] : ($this->config ??= $this->buildConfig($form));
    }

    /** 1 for the first render of this form on the page, then 2, 3... */
    public function getInstanceNumber(): int
    {
        if ($this->instanceNumber === null) {
            $state = $this->layoutState();
            $formId = (int)$this->getForm()?->getId();
            $state->instances[$formId] = ($state->instances[$formId] ?? 0) + 1;
            $this->instanceNumber = $state->instances[$formId];
        }
        return $this->instanceNumber;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function getItemContext(array $item, Escaper $escaper): array
    {
        return $this->itemContext->build(
            $item,
            (int)$this->getForm()?->getId(),
            $this->getInstanceNumber(),
            $escaper,
            $this->fieldTypes->maxLength((string)($item['type'] ?? ''))
        );
    }

    /** @param array<string, mixed> $settings */
    public function getFileAccept(array $settings): string
    {
        return $this->itemContext->fileAccept($settings, $this->moduleConfig->getAllowedExtensions($this->getStoreId()));
    }

    /**
     * Renders a partial through theme fallback; $block is the item Template block.
     *
     * @param Template $block
     * @param array<string, mixed> $vars
     */
    public function renderPartial(object $block, string $partial, array $vars = []): string
    {
        return $block->assign($vars)->fetchView((string)$block->getTemplateFile('Furan_FormBuilder::' . $partial));
    }

    /** @return array<string, mixed> */
    private function buildConfig(FormInterface $form): array
    {
        $urlParams = ['_secure' => $this->getRequest()->isSecure(), '_nosid' => true, '_scope_to_url' => true];

        return $this->configBuilder->build(
            $form,
            $this->getRenderSchema(),
            $this->getStoreId(),
            $this->resolveProductId(),
            $this->getInstanceNumber() === 1,
            fn (string $route, array $query = []): string => $this->getUrl(
                $route,
                $query === [] ? $urlParams : $urlParams + ['_query' => $query]
            ),
            fn (string $fileId): string => $this->getViewFileUrl($fileId)
        );
    }

    /**
     * Public request data, safe only while this block is not block_html cached: if a lifetime is ever added,
     * the product id must join the cache key.
     */
    private function resolveProductId(): ?int
    {
        $request = $this->getRequest();
        return ConfigBuilder::productId(
            $request instanceof Http && $request->getFullActionName() === 'catalog_product_view'
                ? $request->getParam('id')
                : $request->getParam('product')
        );
    }

    public function getConfigJson(): string
    {
        return $this->jsonHexTag->serialize($this->getConfig());
    }

    /** @param array<string, mixed> $item */
    public function renderItem(array $item): string
    {
        $kind = $item['kind'] ?? 'page';
        $template = match ($kind) {
            'field' => $this->fieldTemplate((string)($item['type'] ?? '')),
            'content' => ContentTypes::has((string)($item['type'] ?? ''))
                ? 'Furan_FormBuilder::item/' . $item['type'] . '.phtml' : null,
            'section' => 'Furan_FormBuilder::item/section.phtml',
            'page' => 'Furan_FormBuilder::item/page.phtml',
            default => null,
        };
        if ($template === null) {
            return '';
        }

        if ($kind === 'field') {
            $item = $this->withRenderHints($item);
        }

        return $this->renderTemplate($template, [
            'item' => $item,
            'form_id' => (int)$this->getForm()?->getId(),
            'parent_block' => $this,
        ]);
    }

    public function renderRichText(string $text): string
    {
        return $this->markdown->render($text);
    }

    /**
     * Allowed countries of the rendering store view (address and country templates).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getCountryOptions(): array
    {
        return $this->countryOptions ??= $this->countries->forStore($this->getStoreId());
    }

    /** @return string[] urls of the extension scripts (di.xml `scripts`), in sort order */
    public function getExtensionScripts(): array
    {
        return $this->assetUrls($this->scripts, 'js', 'script');
    }

    /** @return string[] urls of the extension stylesheets (di.xml `styles`), in sort order */
    public function getExtensionStyles(): array
    {
        return $this->assetUrls($this->styles, 'css', 'stylesheet');
    }

    /**
     * Items are 'Vendor_Module::path.ext' or ['file' => ..., 'sortOrder' => int]; invalid ones are logged and skipped.
     *
     * @param array<string, string|array<string, mixed>> $items
     * @return string[]
     */
    private function assetUrls(array $items, string $extension, string $label): array
    {
        $valid = [];
        foreach ($items as $key => $item) {
            $file = is_array($item) ? ($item['file'] ?? null) : $item;
            if (!is_string($file) || str_contains($file, '..')
                || !preg_match('/^[A-Z][A-Za-z0-9]*_[A-Za-z0-9]+::[A-Za-z0-9_.\/-]+\.' . $extension . '$/D', $file)
            ) {
                $this->logger->warning(sprintf('Furan_FormBuilder: extension %s "%s" ignored.', $label, $key));
                continue;
            }
            $valid[] = [is_array($item) ? (int)($item['sortOrder'] ?? 0) : 0, count($valid), $file];
        }
        usort($valid, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(fn (array $entry): string => $this->getViewFileUrl($entry[2]), $valid);
    }

    public function shouldRenderAssets(): bool
    {
        $state = $this->layoutState();
        if ($state->assetsPrinted) {
            return false;
        }
        $state->assetsPrinted = true;
        return true;
    }

    public function usesHyvaThemeStyles(): bool
    {
        return $this->hyvaDetector->isHyva();
    }

    /** True once per page, for the first Theme styles only form on a Hyvä theme; the caller checks the style mode. */
    public function shouldRenderHyvaStyles(): bool
    {
        $state = $this->layoutState();
        if ($state->hyvaStylesPrinted || !$this->usesHyvaThemeStyles()) {
            return false;
        }
        $state->hyvaStylesPrinted = true;
        return true;
    }

    protected function _toHtml(): string
    {
        $form = $this->getForm();
        if ($form === null || empty($form->getSchema()['pages']) || !$this->moduleConfig->isEnabled($this->getStoreId())) {
            return '';
        }
        $closed = $this->schedule->refusal($form);
        if ($closed !== null) {
            return $this->renderTemplate(self::CLOSED_TEMPLATE, ['parent_block' => $this, 'message' => $closed['message']]);
        }
        return $this->renderTemplate((string)($this->getTemplate() ?: self::DEFAULT_TEMPLATE), ['parent_block' => $this]);
    }

    /** Single seam for every template render; tests override it. */
    /** @param array<string, mixed> $vars */
    protected function renderTemplate(string $templateId, array $vars): string
    {
        return $this->getLayout()->createBlock(Template::class)
            ->setTemplate($templateId)
            ->setData($vars)
            ->toHtml();
    }

    /**
     * Hints come from the field type only; a `render` key in a stored schema is never trusted.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function withRenderHints(array $item): array
    {
        unset($item['render']);
        $code = (string)($item['type'] ?? '');
        $type = $this->fieldTypes->has($code) ? $this->fieldTypes->get($code) : null;
        if ($type instanceof RenderHintsInterface) {
            $item['render'] = $type->renderHints($item, $this->getStoreId());
        }
        return $item;
    }

    private function fieldTemplate(string $type): ?string
    {
        if ($this->fieldTypes->has($type) && !$this->fieldTypes->isUsable($type, $this->getStoreId())) {
            return null;
        }
        if (!$this->fieldTypes->has($type)) {
            $this->logger->error(sprintf('Furan_FormBuilder: unknown field type "%s".', $type));
            return null;
        }
        return $this->fieldTypes->get($type)->getTemplate();
    }

    private function layoutState(): \stdClass
    {
        self::$layoutState ??= new \WeakMap();
        return self::$layoutState[$this->getLayout()] ??= (object)['assetsPrinted' => false, 'hyvaStylesPrinted' => false, 'instances' => []];
    }

    private function getStoreId(): int
    {
        return (int)$this->_storeManager->getStore()->getId();
    }
}
