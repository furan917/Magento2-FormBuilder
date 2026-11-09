<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Widget;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Widget\Form;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Storefront\ConfigBuilder;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormTest extends TestCase
{
    public function testItLoadsTheActiveFormFromTheWidgetParameter(): void
    {
        $form = $this->createStub(FormInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->expects($this->once())->method('getActiveById')->with(12, 1)->willReturn($form);

        $block = $this->block($repository, ['form_id' => '12']);

        $this->assertSame($form, $block->getForm());
        $this->assertSame($form, $block->getForm());
    }

    public function testItRendersNothingForAnUnknownOrInactiveForm(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->expects($this->once())->method('getActiveById')
            ->willThrowException(new NoSuchEntityException(__('Missing')));

        $block = $this->block($repository, ['form_id' => 99]);

        $this->assertNull($block->getForm());
        $this->assertNull($block->getForm());
        $this->assertSame('', $block->html());
    }

    public function testItReturnsCacheIdentitiesFromTheWidgetParameter(): void
    {
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getActiveById')->willThrowException(new NoSuchEntityException(__('Inactive')));

        $this->assertSame(['furan_form_7'], $this->block($repository, ['form_id' => '7'])->getIdentities());
        $this->assertSame([], $this->block($repository, [])->getIdentities());
    }

    public function testItPassesTheConfigBuilderThroughTheWidgetBlockConstructor(): void
    {
        $builder = $this->createStub(ConfigBuilder::class);
        $block = $this->block($this->createStub(FormRepositoryInterface::class), [], $builder);

        $property = new \ReflectionProperty(\Furan\FormBuilder\Block\Form::class, 'configBuilder');
        $this->assertSame($builder, $property->getValue($block));
    }

    public function testItRendersExtensionScriptsForTheCmsWidgetAsWellAsTheFormPage(): void
    {
        $scripts = ['b' => ['file' => 'Acme_Extras::js/b.js', 'sortOrder' => 2], 'a' => 'Acme_Extras::js/a.js'];
        $block = $this->block(
            $this->createStub(FormRepositoryInterface::class),
            ['form_id' => '3'],
            null,
            $scripts,
            ['s' => 'Acme_Extras::css/s.css']
        );

        $this->assertSame(['url:Acme_Extras::js/a.js', 'url:Acme_Extras::js/b.js'], $block->getExtensionScripts());
        $this->assertSame(['url:Acme_Extras::css/s.css'], $block->getExtensionStyles());
        $this->assertSame('3', $block->getData('form_id'));
    }

    public function testItInheritsScriptsConfiguredForTheFormBlockInDi(): void
    {
        $config = new \Magento\Framework\ObjectManager\Config\Config(
            new \Magento\Framework\ObjectManager\Relations\Runtime(),
            new \Magento\Framework\ObjectManager\Definition\Runtime()
        );
        $config->extend([\Furan\FormBuilder\Block\Form::class => ['arguments' => [
            'scripts' => ['acme' => 'Acme_Extras::js/acme.js'],
        ]]]);

        $arguments = $config->getArguments(Form::class);

        $this->assertSame(['acme' => 'Acme_Extras::js/acme.js'], $arguments['scripts']);
    }

    private function block(
        FormRepositoryInterface $repository,
        array $data,
        ?ConfigBuilder $configBuilder = null,
        array $scripts = [],
        array $styles = []
    ): Form
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')
            ->willReturn($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class));
        $context->method('getLayout')->willReturn($this->createStub(LayoutInterface::class));
        $context->method('getRequest')->willReturn($this->createStub(Http::class));
        $context->method('getEscaper')
            ->willReturn(\Furan\FormBuilder\Test\Unit\Helper\EscaperFactory::create());

        return new class (
            $context,
            $configBuilder ?? $this->createStub(ConfigBuilder::class),
            new Pool([]),
            $this->createStub(Config::class),
            $this->createStub(LoggerInterface::class),
            new MiniMarkdown(),
            new \Furan\FormBuilder\Model\Form\ScheduleGate($this->createStub(\Magento\Framework\Stdlib\DateTime\DateTime::class)),
            $this->createStub(\Furan\FormBuilder\Model\Directory\CountryOptions::class),
            new \Magento\Framework\Serialize\Serializer\JsonHexTag(),
            $repository,
            \Furan\FormBuilder\Test\Unit\Helper\TranslatorFactory::create($this->createStub(\Furan\FormBuilder\Model\Url\RedirectValidator::class)),
            $scripts,
            $styles,
            $data
        ) extends Form {
            public function getViewFileUrl($fileId, array $params = []): string
            {
                return 'url:' . $fileId;
            }

            public function html(): string
            {
                return $this->_toHtml();
            }
        };
    }
}
