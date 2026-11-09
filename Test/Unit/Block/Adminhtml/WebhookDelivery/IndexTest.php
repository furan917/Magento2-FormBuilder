<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\WebhookDelivery;

use Furan\FormBuilder\Block\Adminhtml\WebhookDelivery\Index;
use Furan\FormBuilder\Model\Webhook\DeliveryLog;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Backend\Block\Template\Context;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Test\Unit\Helper\BackendBlockObjectManager;

class IndexTest extends TestCase
{
    use BackendBlockObjectManager;

    /** @var list<array<int, mixed>> */
    private array $pages = [];

    /** @var list<array<string, mixed>> */
    private array $urls = [];

    /** @param array<string, mixed> $params */
    private function block(string $after, bool $submissions = false, bool $endpointExists = true, array $params = []): Index
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $k) => (['id' => '7', 'after' => $after] + $params)[$k] ?? null
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $urls = $this->createStub(\Magento\Framework\UrlInterface::class);
        $urls->method('getUrl')->willReturnCallback(function (?string $route = null, ?array $p = null): string {
            $this->urls[] = (array)$p;
            return 'https://admin.test/u';
        });
        $context->method('getUrlBuilder')->willReturn($urls);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => $submissions && $r === 'Furan_FormBuilder::submissions');
        $log = $this->createStub(DeliveryLog::class);
        $log->method('page')->willReturnCallback(function (int $endpoint, int $after, int $limit = 50, ?int $status = null, string $find = ''): array {
            $this->pages[] = $status === null && $find === '' ? [$endpoint, $after] : [$endpoint, $after, $status, $find];
            return ['rows' => [['id' => 9, 'submission_id' => 12, 'status' => 3]], 'next' => 9, 'unrecognised' => false];
        });
        $endpoint = $this->createStub(EndpointView::class);
        $endpoint->method('name')->willReturn('CRM');
        $repository = $this->createStub(EndpointRepository::class);
        $repository->method('getView')->willReturnCallback(
            static fn (): EndpointView => $endpointExists ? $endpoint : throw new \Magento\Framework\Exception\NoSuchEntityException(__('gone'))
        );

        $context->method('getAuthorization')->willReturn($auth);

        return new Index($context, $repository, $log);
    }

    public function testItAcceptsOnlyDigitsAsTheCursor(): void
    {
        $this->block('61')->getListing();
        $this->block('61 OR 1=1')->getListing();
        $this->block('-5')->getListing();

        $this->assertSame([[7, 61], [7, 0], [7, 0]], $this->pages);
    }

    public function testItPassesOnlyAKnownStatusAndATrimmedBoundedFindToTheLog(): void
    {
        $this->block('', false, true, ['status' => '3', 'find' => '  #12  '])->getListing();
        $this->block('', false, true, ['status' => '9'])->getListing();
        $this->block('', false, true, ['status' => '3 OR 1', 'find' => ['x']])->getListing();
        $this->block('', false, true, ['find' => str_repeat('a', 200)])->getListing();

        $this->assertSame([[7, 0, 3, '#12'], [7, 0], [7, 0], [7, 0, null, str_repeat('a', 100)]], $this->pages);
    }

    public function testItKeepsTheFiltersOnThePagerLinks(): void
    {
        $block = $this->block('61', false, true, ['status' => '3', 'find' => '12']);
        $block->getNextUrl();
        $block->getFirstUrl();

        $this->assertSame(['after' => 9, 'status' => 3, 'find' => '12'], array_slice($this->urls, -2)[0]['_query']);
        $this->assertSame(['status' => 3, 'find' => '12'], array_slice($this->urls, -1)[0]['_query']);
    }

    public function testItLinksSubmissionsOnlyForAdminsWithTheSubmissionsPermission(): void
    {
        $this->assertSame('https://admin.test/u', $this->block('', true)->getListing()['rows'][0]['submission_url']);
        $this->assertNull($this->block('', false)->getListing()['rows'][0]['submission_url']);
    }

    public function testItTreatsDeliveriesOfAPurgeRequestedOrMissingEndpointAsNotFound(): void
    {
        $block = $this->block('', true, false);

        $this->assertNull($block->getEndpointName());
        $this->assertSame(['rows' => [], 'next' => null, 'unrecognised' => false], $block->getListing());
        $this->assertSame([], $this->pages);
    }
}
