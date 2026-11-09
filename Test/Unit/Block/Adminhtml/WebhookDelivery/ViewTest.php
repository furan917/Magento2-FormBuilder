<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\WebhookDelivery;

use Furan\FormBuilder\Block\Adminhtml\WebhookDelivery\Index;
use Furan\FormBuilder\Block\Adminhtml\WebhookDelivery\View;
use Furan\FormBuilder\Model\Webhook\DeliveryLog;
use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\Block\Template\Context;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Test\Unit\Helper\BackendBlockObjectManager;

class ViewTest extends TestCase
{
    use BackendBlockObjectManager;

    private DeliveryLog $log;

    /** @var list<string> */
    private array $calls = [];

    /** @var list<bool> */
    private array $excerptFlags = [];

    private const MANAGER = [
        'Furan_FormBuilder::webhooks', 'Furan_FormBuilder::webhooks_manage', 'Furan_FormBuilder::submissions_export',
    ];

    /** @param string[] $granted */
    private function context(array $granted, array $params): array
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $k) => $params[$k] ?? null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($this->createStub(\Magento\Framework\UrlInterface::class));
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => in_array($r, $granted, true));
        $context->method('getAuthorization')->willReturn($auth);
        $context->method('getFormKey')->willReturn($this->createStub(FormKey::class));

        return [$context, $auth];
    }

    /** @param string[] $granted */
    private function view(array $granted, bool $debug, bool $personal = false, bool $endpointExists = true): View
    {
        [$context, $auth] = $this->context($granted, ['id' => '40']);
        $endpoint = $this->createStub(EndpointView::class);
        $endpoint->method('debugResponses')->willReturn($debug);
        $endpoint->method('includePersonal')->willReturn($personal);
        $endpoint->method('name')->willReturn('CRM');
        $repository = $this->createStub(EndpointRepository::class);
        $repository->method('getView')->willReturnCallback(function () use ($endpoint, $endpointExists): EndpointView {
            $this->calls[] = 'endpoint';
            return $endpointExists ? $endpoint : throw new NoSuchEntityException(__('gone'));
        });
        $this->log = $this->createStub(DeliveryLog::class);
        $this->log->method('delivery')->willReturnCallback(function (): array {
            $this->calls[] = 'delivery';
            return ['id' => 40, 'endpoint_id' => 7, 'submission_id' => 12];
        });
        $this->log->method('attempts')->willReturnCallback(function (int $id, bool $withExcerpt): array {
            $this->calls[] = 'attempts';
            $this->excerptFlags[] = $withExcerpt;
            return [$withExcerpt ? ['id' => 1, 'excerpt' => 'body'] : ['id' => 1]];
        });

        return new View($context, $repository, $this->log);
    }

    public function testItShowsAttemptsWithoutRequestHeadersAndTheDebugExcerptOnlyToManagers(): void
    {
        $shown = $this->view(self::MANAGER, true)->getDetail();
        $debugOff = $this->view(self::MANAGER, false)->getDetail();

        $this->assertSame([true, false], $this->excerptFlags);
        $this->assertSame('body', $shown['attempts'][0]['excerpt']);
        $this->assertArrayNotHasKey('excerpt', $debugOff['attempts'][0]);
    }

    public function testItHidesResponseExcerptsFromManagersWithoutExportPermission(): void
    {
        $noExport = ['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::webhooks_manage'];
        $viewer = $this->view(['Furan_FormBuilder::webhooks'], true)->getDetail();
        $manager = $this->view($noExport, true)->getDetail();

        $this->assertSame([false, false], $this->excerptFlags);
        $this->assertArrayNotHasKey('excerpt', $viewer['attempts'][0]);
        $this->assertArrayNotHasKey('excerpt', $manager['attempts'][0]);
    }

    public function testItHidesResponseExcerptsOfAPersonalEndpointWithoutThePersonalPermission(): void
    {
        $this->view(self::MANAGER, true, true)->getDetail();
        $this->view([...self::MANAGER, 'Furan_FormBuilder::webhooks_personal'], true, true)->getDetail();

        $this->assertSame([false, true], $this->excerptFlags);
    }

    public function testItDoesNotJoinExcerptsBeforeTheEndpointIsKnown(): void
    {
        $this->view(self::MANAGER, true)->getDetail();

        $this->assertSame(['delivery', 'endpoint', 'attempts'], $this->calls);
    }

    public function testItTreatsDeliveriesOfAPurgeRequestedOrMissingEndpointAsNotFound(): void
    {
        $block = $this->view(self::MANAGER, true, false, false);

        $this->assertNull($block->getDetail());
        $this->assertSame(['delivery', 'endpoint'], $this->calls);
        $this->assertSame('', $block->getEndpointName());
    }

    public function testItLinksTheSubmissionOnlyForAdminsWhoMayOpenSubmissions(): void
    {
        $with = $this->view(['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::submissions'], false);
        $without = $this->view(['Furan_FormBuilder::webhooks'], false);

        $this->assertTrue($with->canOpenSubmissions());
        $this->assertFalse($without->canOpenSubmissions());
    }

    public function testItOffersReplayOnlyWithTheReplayPermission(): void
    {
        $this->assertTrue($this->view(['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::webhooks_replay'], false)->canReplay());
        $this->assertFalse($this->view(['Furan_FormBuilder::webhooks'], false)->canReplay());
        $this->assertFalse($this->view(['Furan_FormBuilder::webhooks_replay'], false)->canReplay());
    }

    public function testItLabelsEachDeliveryErrorInPlainWords(): void
    {
        $labels = array_map(DeliveryStatus::errorLabel(...), DeliveryStatus::ERRORS);

        $this->assertCount(count(DeliveryStatus::ERRORS), array_unique($labels));
        foreach ($labels as $i => $label) {
            $this->assertNotSame('', $label);
            $this->assertNotSame(DeliveryStatus::ERRORS[$i], $label);
        }
        $this->assertSame('Unknown error', DeliveryStatus::errorLabel('something_new'));
    }

    public function testItLabelsEveryDeliveryStatus(): void
    {
        $labels = array_map(DeliveryStatus::label(...), DeliveryStatus::STATUSES);

        $this->assertCount(6, array_unique($labels));
        $this->assertNotContains('', $labels);
    }
}
