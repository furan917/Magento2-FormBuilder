<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Form\Preview;
use Furan\FormBuilder\Model\Form\PreviewToken;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Test\Unit\Helper\TranslatorFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PreviewTest extends TestCase
{
    private int $now = 1800000000;
    /** @var array<string, string> */
    private array $headers = [];
    private ?string $robots = null;
    private DataObject $formBlock;
    private DataObject $noticeBlock;
    private bool $repositoryCalled = false;

    private function tokens(): PreviewToken
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn('k0');

        return new PreviewToken(new SigningKeys($config), fn (): int => $this->now);
    }

    /** @param array<string, mixed> $params */
    private function controller(array $params, bool $formExists = true): Preview
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getTitle')->willReturn('Contact');
        $form->method('isActive')->willReturn(false);
        $form->method('getSettings')->willReturn([]);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(function (int $id) use ($form, $formExists): FormInterface {
            $this->repositoryCalled = true;
            if (!$formExists || $id !== 7) {
                throw new NoSuchEntityException();
            }
            return $form;
        });
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->formBlock = new DataObject();
        $this->noticeBlock = new DataObject();
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturnCallback(fn (string $name) => match ($name) {
            'furanform.form' => $this->formBlock,
            'furanform.preview.notice' => $this->noticeBlock,
            default => false,
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($this->createStub(Title::class));
        $config->method('setRobots')->willReturnCallback(function (string $robots): void {
            $this->robots = $robots;
        });
        $page = $this->createStub(Page::class);
        $page->method('getLayout')->willReturn($layout);
        $page->method('getConfig')->willReturn($config);
        $page->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($page): Page {
            $this->headers[$name] = $value;
            return $page;
        });
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);
        $forward = $this->createStub(Forward::class);
        $forward->method('forward')->willReturnSelf();
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($forward);

        return new Preview(
            $pageFactory,
            $request,
            $repository,
            $storeManager,
            $forwardFactory,
            $this->createStub(LoggerInterface::class),
            TranslatorFactory::create($this->createStub(\Furan\FormBuilder\Model\Url\RedirectValidator::class)),
            $this->tokens()
        );
    }

    public function testItRendersAnInactiveFormWithAValidTokenAsAnUncachedNoindexPreview(): void
    {
        $result = $this->controller(['id' => '7', 'token' => $this->tokens()->issue(7)])->execute();

        $this->assertInstanceOf(Page::class, $result);
        $this->assertSame(7, $this->formBlock->getData('form')->getId());
        $this->assertTrue($this->noticeBlock->getData('inactive'));
        $this->assertSame('NOINDEX,NOFOLLOW', $this->robots);
        $this->assertStringContainsString('no-store', $this->headers['Cache-Control']);
        $this->assertSame('noindex', $this->headers['X-Robots-Tag']);
    }

    public function testItRefusesATamperedToken(): void
    {
        [$expires, $mac] = explode('.', $this->tokens()->issue(7));

        $this->assertInstanceOf(Forward::class, $this->controller(['id' => '7', 'token' => $expires . '.' . strrev($mac)])->execute());
        $this->assertFalse($this->repositoryCalled);
    }

    public function testItRefusesAnExpiredToken(): void
    {
        $token = $this->tokens()->issue(7);
        $this->now += 7201;

        $this->assertInstanceOf(Forward::class, $this->controller(['id' => '7', 'token' => $token])->execute());
        $this->assertFalse($this->repositoryCalled);
    }

    public function testItRefusesATokenForAnotherForm(): void
    {
        $this->assertInstanceOf(Forward::class, $this->controller(['id' => '7', 'token' => $this->tokens()->issue(8)])->execute());
        $this->assertFalse($this->repositoryCalled);
    }

    public function testItRefusesAMissingOrMalformedToken(): void
    {
        $this->assertInstanceOf(Forward::class, $this->controller(['id' => '7'])->execute());
        $this->assertInstanceOf(Forward::class, $this->controller(['id' => '7', 'token' => [$this->tokens()->issue(7)]])->execute());
        $this->assertInstanceOf(Forward::class, $this->controller(['id' => 'x', 'token' => $this->tokens()->issue(0)])->execute());
        $this->assertFalse($this->repositoryCalled);
    }

    public function testItRefusesAFormThatNoLongerExists(): void
    {
        $this->assertInstanceOf(Forward::class, $this->controller(['id' => '7', 'token' => $this->tokens()->issue(7)], false)->execute());
    }

    public function testItShowsThePreviewNoticeOnlyForAnInactiveForm(): void
    {
        $render = static function (bool $inactive): string {
            $block = new \Magento\Framework\DataObject(['inactive' => $inactive]);
            $escaper = \Furan\FormBuilder\Test\Unit\Helper\EscaperFactory::create();
            ob_start();
            include __DIR__ . '/../../../../view/frontend/templates/form/preview-notice.phtml';

            return (string)ob_get_clean();
        };

        $this->assertStringContainsString('Preview: this form is not active. Answers cannot be sent.', $render(true));
        $this->assertSame('', trim($render(false)));
    }

    public function testItKeepsThePreviewPageOutOfTheFullPageCache(): void
    {
        $layout = simplexml_load_file(__DIR__ . '/../../../../view/frontend/layout/furanform_form_preview.xml');

        $this->assertSame('furanform_form_view', (string)$layout->xpath('//update')[0]['handle']);
        $this->assertSame('false', (string)$layout->xpath('//block[@name="furanform.preview.notice"]')[0]['cacheable']);
    }
}
