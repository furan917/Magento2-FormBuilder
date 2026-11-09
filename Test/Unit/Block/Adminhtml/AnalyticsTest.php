<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Adminhtml\Analytics;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Stats\AnalyticsNotices;
use Furan\FormBuilder\Model\Stats\FreeTextReader;
use Furan\FormBuilder\Model\Stats\Query;
use Furan\FormBuilder\Model\Stats\RebuildQueue;
use Magento\Framework\Data\Form\FormKey;
use Magento\Customer\Model\Customer\Source\GroupSourceInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\UrlInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Test\Unit\Helper\BackendBlockObjectManager;

class AnalyticsTest extends TestCase
{
    use BackendBlockObjectManager;

    private array $params = ['form_id' => '5'];
    private array $allowed = ['Furan_FormBuilder::submissions' => true];
    private FreeTextReader&Stub $reader;
    private Query&Stub $query;

    private array $queueState = ['needs_rebuild' => false, 'rebuilding' => false, 'queued' => false, 'queued_at' => null];

    private function block(): Analytics
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $k) => $this->params[$k] ?? null);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $p, array $q) => '/' . $p . '?' . http_build_query($q['_query'] ?? [])
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);
        $queue = $this->createMock(RebuildQueue::class);
        $queue->method('state')->with(5)->willReturnCallback(fn () => $this->queueState);
        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('KEY');
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(fn (string $r) => $this->allowed[$r] ?? false);
        $context->method('getAuthorization')->willReturn($auth);
        $context->method('getFormKey')->willReturn($formKey);
        $context->method('getStoreManager')->willReturn($this->createStub(StoreManagerInterface::class));
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn(['pages' => [['items' => [
            ['kind' => 'field', 'name' => 'comment', 'type' => 'textarea', 'label' => 'Comment'],
            ['kind' => 'section', 'items' => [['kind' => 'field', 'name' => 'nick', 'type' => 'text', 'label' => 'Nick']]],
        ]]]]);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(5)->willReturn($form);
        $this->query->method('charts')->willReturn(['charts' => 'x', 'fields' => [], 'removed' => [], 'time' => [], 'granularity' => 'month', 'from' => '2026-01-01', 'to' => '2026-11-07', 'submissions' => 0]);
        return new Analytics(
            $context,
            $repository,
            $this->query,
            $this->reader,
            $this->createStub(Config::class),
            $this->createStub(AnalyticsNotices::class),
            new JsonHexTag(new Json()),
            $this->createStub(GroupSourceInterface::class),
            $queue
        );
    }

    protected function setUp(): void
    {
        $this->reader = $this->createStub(FreeTextReader::class);
        $this->query = $this->createStub(Query::class);
    }

    public function testItHidesFreeTextAnswersFromUsersWithoutTheSubmissionsPermission(): void
    {
        $reader = $this->reader = $this->createMock(FreeTextReader::class);
        $this->allowed = [];
        $reader->expects($this->never())->method('read');

        $this->assertSame([], $this->block()->getFreeText());
    }

    public function testItReadsFreeTextForTheSubmissionsPermissionHolderFromTheFlattenedSchema(): void
    {
        $reader = $this->reader = $this->createMock(FreeTextReader::class);
        $this->params['store_id'] = '2';
        $reader->expects($this->once())->method('read')->with(
            5,
            $this->callback(static fn (array $f) => array_column($f, 'name') === ['comment', 'nick']),
            ['store_id' => 2],
            null,
            null
        )->willReturn(['comment' => ['answers' => [], 'cursor' => null]]);

        $this->assertArrayHasKey('comment', $this->block()->getFreeText());
    }

    public function testItLinksOlderAnswersBackToTheSameControllerWithFieldAndCursor(): void
    {
        $this->params['from'] = '2026-01-01';
        $this->params['to'] = 'not a date';

        $url = $this->block()->getOlderUrl('comment', 120);

        $this->assertStringStartsWith('/*/*/index?', $url);
        $this->assertStringContainsString('form_id=5', $url);
        $this->assertStringContainsString('field=comment', $url);
        $this->assertStringContainsString('cursor=120', $url);
        $this->assertStringContainsString('from=2026-01-01', $url);
        $this->assertStringNotContainsString('to=', $url);
    }

    public function testItExposesTheEffectiveRangeForPrefillingTheDateInputs(): void
    {
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-11-07'], $this->block()->getEffectiveRange());
    }

    public function testItEncodesChartDataSafelyForAnApplicationJsonBlock(): void
    {
        $json = $this->block()->getDataJson();

        $this->assertStringNotContainsString('<', $json);
        $this->assertArrayHasKey('i18n', json_decode($json, true));
    }

    public function testItShowsARebuildNowButtonWhileTheFormNeedsARebuildAndIsNotQueued(): void
    {
        $this->queueState = ['needs_rebuild' => true, 'rebuilding' => false, 'queued' => false, 'queued_at' => null];
        $this->allowed['Furan_FormBuilder::analytics'] = true;
        $block = $this->block();

        $this->assertTrue($block->canQueueRebuild());
        $this->assertNull($block->getQueuedNotice());
        $this->assertSame('/furanform/analytics/queueRebuild?form_id=5', $block->getQueueRebuildUrl());
        $this->assertSame('KEY', $block->getFormKey());
    }

    public function testItShowsTheQueuedStateInsteadOfTheButtonOnceARebuildIsQueued(): void
    {
        $this->queueState = ['needs_rebuild' => true, 'rebuilding' => false, 'queued' => true, 'queued_at' => '2026-11-07 01:00:00'];
        $block = $this->block();

        $this->assertFalse($block->canQueueRebuild());
        $this->assertSame(
            'A statistics rebuild is queued for the next off-peak run (queued 2026-11-07 01:00:00).',
            (string)$block->getQueuedNotice()
        );
    }

    public function testItHidesTheRebuildButtonWithoutTheAnalyticsPermissionOrWhileRebuilding(): void
    {
        $this->queueState = ['needs_rebuild' => true, 'rebuilding' => true, 'queued' => false, 'queued_at' => null];
        $this->allowed['Furan_FormBuilder::analytics'] = true;
        $this->assertFalse($this->block()->canQueueRebuild());

        $this->queueState['rebuilding'] = false;
        $this->allowed['Furan_FormBuilder::analytics'] = false;
        $this->assertFalse($this->block()->canQueueRebuild());
    }
}
