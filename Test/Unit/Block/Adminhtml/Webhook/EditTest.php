<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Webhook;

use Furan\FormBuilder\Block\Adminhtml\Webhook\Edit;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Model\Source\AllForms;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\SecretStash;
use Furan\FormBuilder\Test\Unit\Model\Webhook\WebhookFixtures;
use Magento\Framework\Data\Form\FormKey;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Test\Unit\Helper\BackendBlockObjectManager;

class EditTest extends TestCase
{
    use BackendBlockObjectManager;

    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private SecretStash $stash;
    private EndpointRepository $repository;

    /** @param string[] $granted */
    private function block(array $granted, ?EndpointView $view = null, ?array $persisted = null, ?int $requestedId = null): Edit
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $k) => $k === 'id' && ($view || $requestedId) ? (string)($requestedId ?? $view->id()) : null);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => in_array('Furan_FormBuilder::' . $r, $granted, true)
            || in_array($r, $granted, true));
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $this->repository = $this->createStub(EndpointRepository::class);
        $this->repository->method('getView')->willReturnCallback(static fn () => $view ?? throw new \Magento\Framework\Exception\NoSuchEntityException());
        $this->stash = $this->double('stash', SecretStash::class);
        $persistor = $this->createStub(DataPersistorInterface::class);
        $persistor->method('get')->willReturnCallback(
            static fn (string $key) => $key === \Furan\FormBuilder\Model\Admin\PersistKeys::webhook(7) ? $persisted : null
        );
        $context->method('getStoreManager')->willReturn($this->createStub(StoreManagerInterface::class));
        $context->method('getAuthorization')->willReturn($auth);
        $context->method('getFormKey')->willReturn($this->createStub(FormKey::class));
        $context->method('getEscaper')->willReturn(EscaperFactory::create());

        return new Edit($context, $this->repository, $this->stash, $persistor, $this->createStub(AllForms::class));
    }

    private function view(bool $personal = false): EndpointView
    {
        $fx = new WebhookFixtures($this->createStub(...));
        $fx->rows = [WebhookFixtures::row([
            'url' => 'https://crm.example.com/in?token=abc', 'include_personal' => $personal ? '1' : '0',
        ])];

        return $fx->repository->getView(7);
    }

    private const ALL = [
        'Furan_FormBuilder::webhooks', 'Furan_FormBuilder::webhooks_manage', 'Furan_FormBuilder::submissions_export',
    ];

    public function testItRendersReadOnlyForAdminsWithoutManagePermission(): void
    {
        $viewOnly = $this->block(['Furan_FormBuilder::webhooks'], $this->view());
        $noExport = $this->block(['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::webhooks_manage'], $this->view());
        $manager = $this->block(self::ALL, $this->view());

        $this->assertTrue($viewOnly->isReadOnly());
        $this->assertTrue($noExport->isReadOnly());
        $this->assertFalse($manager->isReadOnly());
        $this->assertSame('https://crm.example.com/…', $viewOnly->getValues()['url']);
        $this->assertSame('https://crm.example.com/in?token=abc', $manager->getValues()['url']);
    }

    public function testItLocksTheReceiverOfAPersonalDataEndpointForAdminsWithoutThePersonalPermission(): void
    {
        $this->assertTrue($this->block(self::ALL, $this->view(true))->isReceiverLocked());
        $this->assertFalse($this->block(self::ALL, $this->view(false))->isReceiverLocked());
        $this->assertFalse($this->block(array_merge(self::ALL, ['Furan_FormBuilder::webhooks_personal']), $this->view(true))->isReceiverLocked());
    }

    public function testItReadsTheSecretFromTheStashOnlyOnce(): void
    {
        $this->asserted = ['stash'];
        $block = $this->block(self::ALL, $this->view());
        $this->stash->expects($this->once())->method('peek')->with(7)->willReturn('whsec_abc');

        $this->assertSame('whsec_abc', $block->getRevealedSecret());
        $this->assertSame('whsec_abc', $block->getRevealedSecret());
    }

    public function testItNeverRevealsASecretToReadOnlyAdmins(): void
    {
        $this->asserted = ['stash'];
        $block = $this->block(['Furan_FormBuilder::webhooks'], $this->view());
        $this->stash->expects($this->never())->method('peek');

        $this->assertNull($block->getRevealedSecret());
    }

    public function testItShowsAFailedSavesInputInsteadOfTheStoredValues(): void
    {
        $block = $this->block(self::ALL, $this->view(), ['name' => 'Typed', 'url' => 'https://typed.example.com/']);

        $this->assertSame('Typed', $block->getValues()['name']);
        $this->assertSame('https://typed.example.com/', $block->getValues()['url']);
        $this->assertSame('store', $block->getValues()['scope_type']);
    }

    public function testItRendersTheEditValuesAfterAFailedSaveWithFieldNames(): void
    {
        $persisted = ['name' => 'Typed', 'fields' => "email\nphone", 'field_mode' => 'selected'];
        $values = $this->block(self::ALL, $this->view(), $persisted)->getValues();

        $this->assertSame("email\nphone", $values['fields']);
        $this->assertSame('selected', $values['field_mode']);
    }

    public function testItKeepsFailedSaveValuesPerEndpoint(): void
    {
        $persisted = ['name' => 'Typed'];
        $this->assertSame('Typed', $this->block(self::ALL, $this->view(), $persisted)->getValues()['name']);
        $this->assertSame('CRM', $this->block(self::ALL, $this->view(), $persisted, 8)->getValues()['name']);
        $this->assertSame('', $this->block(self::ALL, null, $persisted, 0)->getValues()['name']);
        $this->assertSame('furan_formbuilder_webhook_7', \Furan\FormBuilder\Model\Admin\PersistKeys::webhook(7));
        $this->assertSame('furan_formbuilder_webhook_new', \Furan\FormBuilder\Model\Admin\PersistKeys::webhook(null));
    }

    public function testItHidesTheUrlPathFromReadOnlyViewers(): void
    {
        $block = $this->block(['Furan_FormBuilder::webhooks'], $this->view());

        $this->assertSame('https://hooks.slack.com:8443/…', $block->maskUrl('https://hooks.slack.com:8443/services/T0/B0/secret'));
        $this->assertSame('https://hooks.slack.com/…', $block->maskUrl('https://hooks.slack.com?token=abc'));
        $this->assertSame('https://hooks.slack.com', $block->maskUrl('https://hooks.slack.com'));
    }

    public function testItExposesTheErrorForEachFieldAfterAFailedSave(): void
    {
        $block = $this->block(self::ALL, $this->view(), ['name' => 'x', 'field_errors' => ['url' => 'Bad url.']]);

        $this->assertSame('Bad url.', $block->getFieldError('url'));
        $this->assertNull($block->getFieldError('name'));
        $this->assertSame('x', $block->getValues()['name']);
    }

    public function testItRendersEachFieldErrorEscapedWithAriaInvalidAndTheErrorIdAfterTheHint(): void
    {
        $block = $this->block(self::ALL, $this->view(), ['field_errors' => ['url' => 'Bad <url>', 'events' => 'Pick one']]);

        $this->assertSame('<p class="ffb-rules-error" id="ffb-webhook-url-error">Bad &lt;url&gt;</p>', $block->getFieldErrorHtml('url'));
        $this->assertSame('', $block->getFieldErrorHtml('name'));
        $this->assertSame(
            ' aria-invalid="true" aria-describedby="ffb-webhook-url-hint ffb-webhook-url-error"',
            $block->getFieldAria('url', 'ffb-webhook-url-hint')
        );
        $this->assertSame(' aria-describedby="ffb-webhook-events-error"', $block->getFieldAria('events', '', false));
        $this->assertSame(' aria-describedby="ffb-webhook-scope-hint"', $block->getFieldAria('scope', 'ffb-webhook-scope-hint', false));
        $this->assertSame('', $block->getFieldAria('name'));
    }

    public function testItKeepsTheRevealedSecretWhenThePageFailsToRender(): void
    {
        $this->asserted = ['stash'];
        $block = $this->block(self::ALL, $this->view());
        $this->stash->method('peek')->with(7)->willReturn('whsec_abc');
        $this->stash->expects($this->once())->method('take')->with(7);

        $this->assertSame('whsec_abc', $block->getRevealedSecret());
        $after = new \ReflectionMethod($block, '_afterToHtml');
        $after->invoke($block, '<html>');
    }

    public function testItDoesNotTakeASecretThatWasNeverShown(): void
    {
        $this->asserted = ['stash'];
        $block = $this->block(self::ALL, $this->view());
        $this->stash->method('peek')->willReturn(null);
        $this->stash->expects($this->never())->method('take');

        $block->getRevealedSecret();
        (new \ReflectionMethod($block, '_afterToHtml'))->invoke($block, '<html>');
    }
}
