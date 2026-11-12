<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\PublicSchema;
use Furan\FormBuilder\Model\Validation\Rule\MinLength;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Block\Stub\TestableForm;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RenderedFormSnapshotTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/';

    public function testItMatchesTheGoldenRenderedFormSnapshot(): void
    {
        $html = $this->renderForm();
        $file = self::FIXTURES . 'rendered-form.html';

        if (getenv('FFB_UPDATE_SNAPSHOT') === '1') {
            file_put_contents($file, $html);
        }

        $this->assertFileExists($file);
        $this->assertSame($this->normalise((string)file_get_contents($file)), $this->normalise($html));
    }

    public function testItRendersTheContractHooksTheJavaScriptRelaysOn(): void
    {
        $html = $this->renderForm();

        foreach (
            [
                'data-ffb-root', 'data-ffb-form', 'data-ffb-progress', 'data-ffb-config', 'data-ffb-prev',
                'data-ffb-next', 'data-ffb-submit', 'data-ffb-status', 'data-ffb-success',
                'data-ffb-name="name"', 'data-ffb-type="hidden"',
                'https://shop.test/furanform/form/submit/',
                'https://static.test/ffb-core.js', 'https://static.test/ffb-types.js', 'https://static.test/ffb-drafts.js', 'https://static.test/ffb-submit.js', 'https://static.test/ffb-form.js', 'https://static.test/ffb.css',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $html);
        }

        preg_match('#data-ffb-config="([^"]*)"#', $html, $match);
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://shop.test/furanform/form/token/?form_id=7', $config['tokenUrl']);
        $this->assertSame('https://shop.test/furanform/form/submit/', $config['submitUrl']);
    }

    private function normalise(string $html): string
    {
        return trim((string)preg_replace('/>\s+</', '><', (string)preg_replace('/\s+/', ' ', $html)));
    }

    private function renderForm(): string
    {
        $schema = json_decode((string)file_get_contents(self::FIXTURES . 'render-schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getSettings')->willReturn([
            'submit_label' => 'Submit', 'prev_label' => 'Back', 'next_label' => 'Next',
            'draft_mode' => 'session', 'show_progress' => true,
        ]);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $request = $this->createStub(Http::class);
        $request->method('isSecure')->willReturn(true);
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $context->method('getLayout')->willReturn($this->createStub(LayoutInterface::class));
        $context->method('getRequest')->willReturn($request);
        $context->method('getEscaper')->willReturn(EscaperFactory::create());

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getDraftTtlHours')->willReturn(24);
        $config->method('getMaxUploadMb')->willReturn(5);
        $config->method('getAllowedExtensions')->willReturn(['pdf', 'png']);

        $types = [];
        foreach (['Text', 'Textarea', 'Email', 'Radio', 'File', 'Hidden'] as $name) {
            $class = 'Furan\\FormBuilder\\Model\\FieldType\\' . $name;
            $types[strtolower($name)] = new $class();
        }
        $fieldTypes = new Pool($types);
        $publicSchema = new PublicSchema(new RulePool(['min_length' => new MinLength()]), $fieldTypes, $config, new \Furan\FormBuilder\Model\Upload\ExtensionList());

        $block = new class (
            $context,
            new \Furan\FormBuilder\Model\Storefront\ConfigBuilder(
                $publicSchema,
                $fieldTypes,
                $config,
                new Json(),
                $this->createStub(LoggerInterface::class),
                $this->createStub(\Furan\FormBuilder\Model\Spam\ReCaptcha::class),
                new \Furan\FormBuilder\Model\Storefront\Eagerness(new \Furan\FormBuilder\Model\Prefill\Sources(), $fieldTypes),
                $this->createStub(ManagerInterface::class)
            ),
            $fieldTypes,
            $config,
            $this->createStub(LoggerInterface::class),
            new MiniMarkdown(),
            new \Furan\FormBuilder\Model\Form\ScheduleGate($this->createStub(\Magento\Framework\Stdlib\DateTime\DateTime::class)),
            $this->createStub(\Furan\FormBuilder\Model\Directory\CountryOptions::class),
            new \Magento\Framework\Serialize\Serializer\JsonHexTag(),
            $this->createStub(\Furan\FormBuilder\Model\Theme\HyvaDetector::class),
            new \Furan\FormBuilder\Block\ItemContext(new \Furan\FormBuilder\Model\Upload\ExtensionList())
        ) extends TestableForm {
            public function shouldRenderAssets(): bool
            {
                return true;
            }

            public function getUrl($route = '', $params = []): string
            {
                $query = isset($params['_query']) ? '?' . http_build_query($params['_query']) : '';
                return 'https://shop.test/' . $route . '/' . $query;
            }

            public function getViewFileUrl($fileId, array $params = []): string
            {
                return 'https://static.test/' . basename(explode('::', $fileId)[1]);
            }
        };
        $block->setForm($form);

        return $block->toHtml();
    }
}
