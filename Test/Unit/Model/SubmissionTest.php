<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class SubmissionTest extends TestCase
{
    private function create(): Submission
    {
        return new Submission(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $this->createStub(SubmissionResource::class)
        );
    }

    public function testItDispatchesModelEventsUnderTheSubmissionPrefix(): void
    {
        $property = fn (string $name) => (new \ReflectionProperty(Submission::class, $name))
            ->getValue($this->create());

        $this->assertSame('furan_formbuilder_submission', $property('_eventPrefix'));
        $this->assertSame('submission', $property('_eventObject'));
    }

    public function testItStoresThePayloadAsJsonAndReturnsItAsAnArray(): void
    {
        $submission = $this->create();
        $payload = [['name' => 'a', 'label' => 'A', 'type' => 'text', 'value' => 'x']];
        $submission->setPayload($payload);

        $this->assertSame(json_encode($payload), $submission->getData('payload_json'));
        $this->assertSame($payload, $submission->getPayload());
    }

    public function testItReturnsAnEmptyPayloadWhenStoredJsonIsInvalid(): void
    {
        $submission = $this->create();
        $submission->setData('payload_json', '{not json');

        $this->assertSame([], $submission->getPayload());
    }

    public function testItDecodesPayloadAndContextAgainOnlyWhenTheStoredJsonChanges(): void
    {
        $submission = $this->create();
        $submission->setPayload([['name' => 'a', 'value' => 'x']])->setContext(['sku' => 'A-1']);
        $payload = $submission->getPayload();
        $payload[0]['value'] = 'changed';

        $this->assertSame([['name' => 'a', 'value' => 'x']], $submission->getPayload());
        $submission->setData('payload_json', '[{"name":"b"}]');
        $this->assertSame([['name' => 'b']], $submission->getPayload());
        $this->assertSame(['sku' => 'A-1'], $submission->getContext());
        $submission->setData('context_json', '"scalar"');
        $this->assertNull($submission->getContext());
        $submission->setData('context_json', '{broken');
        $this->assertNull($submission->getContext());
    }

    public function testItCastsNullableStoreAndCustomerIds(): void
    {
        $submission = $this->create();
        $this->assertNull($submission->getStoreId());
        $this->assertNull($submission->getCustomerId());

        $submission->setData('store_id', '3');
        $submission->setData('customer_id', '7');
        $this->assertSame(3, $submission->getStoreId());
        $this->assertSame(7, $submission->getCustomerId());

        $submission->setStoreId(null)->setCustomerId(null);
        $this->assertNull($submission->getStoreId());
        $this->assertNull($submission->getCustomerId());
    }

    public function testItExposesStatusCustomerGroupEmailHashAndContextThroughTheSubmissionModel(): void
    {
        $submission = $this->create();
        $this->assertSame(0, $submission->getStatus());
        $this->assertSame(0, $submission->getCustomerGroupId());
        $this->assertNull($submission->getEmailHash());
        $this->assertNull($submission->getEmailHashVersion());
        $this->assertNull($submission->getContext());
        $this->assertNull($submission->getUpdatedAt());

        $hash = random_bytes(32);
        $context = ['id' => 5, 'sku' => 'A-1', 'name' => 'Caf\u{e9}', 'url' => 'https://x.test/a', 'price' => 9.5, 'currency' => 'EUR'];
        $submission->setStatus(SubmissionInterface::STATUS_ANSWERED)
            ->setCustomerGroupId(3)
            ->setEmailHash($hash)
            ->setEmailHashVersion(2)
            ->setContext($context)
            ->setCreatedAt('2026-11-06 10:00:00');

        $this->assertSame(2, $submission->getStatus());
        $this->assertSame(3, $submission->getCustomerGroupId());
        $this->assertSame($hash, $submission->getEmailHash());
        $this->assertSame(2, $submission->getEmailHashVersion());
        $this->assertSame($context, $submission->getContext());
        $this->assertSame('2026-11-06 10:00:00', $submission->getCreatedAt());

        $submission->setEmailHash(null)->setEmailHashVersion(null)->setContext(null);
        $this->assertNull($submission->getEmailHash());
        $this->assertNull($submission->getEmailHashVersion());
        $this->assertNull($submission->getContext());
        $this->assertNull($submission->getData('context_json'));
    }

    public function testItDefinesTheStatusConstants(): void
    {
        $this->assertSame(
            [0, 1, 2, 3],
            [
                SubmissionInterface::STATUS_NEW,
                SubmissionInterface::STATUS_IN_PROGRESS,
                SubmissionInterface::STATUS_ANSWERED,
                SubmissionInterface::STATUS_CLOSED,
            ]
        );
    }
}
