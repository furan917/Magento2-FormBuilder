<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\Outbox;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveries;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionDeliveriesTest extends TestCase
{
    private Outbox&Stub $outbox;
    private UrlPolicy&Stub $policy;

    private function deliveries(bool $enabled): SubmissionDeliveries
    {
        $this->outbox = $this->createMock(Outbox::class);
        $this->policy = $this->createStub(UrlPolicy::class);
        $this->policy->method('isEnabled')->willReturn($enabled);

        return new SubmissionDeliveries($this->outbox, $this->policy);
    }

    public function testItEnqueuesNothingWhileWebhooksAreDisabledInEnvPhp(): void
    {
        $deliveries = $this->deliveries(false);
        $this->outbox->expects($this->never())->method('record');

        $this->assertSame([], $deliveries->record(1, 2, 3, 'submission.created'));
    }

    public function testItRecordsThroughTheOutboxWhileWebhooksAreEnabled(): void
    {
        $deliveries = $this->deliveries(true);
        $this->outbox->expects($this->once())->method('record')
            ->with(1, 2, 3, 'submission.status_changed', ['old' => 0, 'new' => 1, 'source' => 'admin'])
            ->willReturn([7, 8]);

        $this->assertSame(
            [7, 8],
            $deliveries->record(1, 2, 3, 'submission.status_changed', ['old' => 0, 'new' => 1, 'source' => 'admin'])
        );
    }

    public function testItWakesTheWorkerOnlyWhenDeliveriesWereWritten(): void
    {
        $deliveries = $this->deliveries(true);
        $this->outbox->expects($this->once())->method('wake')->with([7]);

        $deliveries->wake([]);
        $deliveries->wake([7]);
    }

    public function testItRecordsNothingForABatchWhileWebhooksAreSwitchedOff(): void
    {
        $deliveries = $this->deliveries(false);
        $this->outbox->expects($this->never())->method('recordMany');

        $this->assertSame([], $deliveries->recordMany('submission.status_changed', [
            ['submissionId' => 1, 'formId' => 2, 'storeId' => 3, 'eventData' => []],
        ]));
    }

    public function testItRecordsABatchThroughTheOutboxWhileWebhooksAreEnabled(): void
    {
        $deliveries = $this->deliveries(true);
        $items = [['submissionId' => 1, 'formId' => 2, 'storeId' => 3, 'eventData' => ['old' => 0, 'new' => 1]]];
        $this->outbox->expects($this->once())->method('recordMany')->with('submission.status_changed', $items)
            ->willReturn([9]);

        $this->assertSame([9], $deliveries->recordMany('submission.status_changed', $items));
    }
}
