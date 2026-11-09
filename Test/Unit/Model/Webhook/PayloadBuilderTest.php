<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\PayloadBuilder;
use Furan\FormBuilder\Model\Webhook\PayloadTooLargeException;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PayloadBuilderTest extends TestCase
{
    private const SCHEMA = ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'items' => [
        ['kind' => 'field', 'type' => 'text', 'name' => 'topic', 'label' => 'Topic', 'personal' => false],
        ['kind' => 'field', 'type' => 'text', 'name' => 'full_name', 'label' => 'Name', 'personal' => true],
        ['kind' => 'section', 'items' => [
            ['kind' => 'field', 'type' => 'file', 'name' => 'cv', 'label' => 'CV', 'personal' => false],
        ]],
    ]]]];

    private const PAYLOAD = [
        ['name' => 'topic', 'label' => 'Topic', 'type' => 'text', 'value' => 'sales'],
        ['name' => 'full_name', 'label' => 'Name', 'type' => 'text', 'value' => 'Ada Lovelace'],
        ['name' => 'cv', 'label' => 'CV', 'type' => 'file',
            'value' => ['file' => 'furan_formbuilder/7/2026/11/abc', 'name' => 'cv.pdf', 'size' => 1024, 'mime' => 'application/pdf']],
        ['name' => 'old_field', 'label' => 'Old', 'type' => 'text', 'value' => 'legacy'],
    ];

    public function testItBuildsTheEnvelopeWithIdTypeTimestampVersionAndData(): void
    {
        $envelope = $this->build($this->endpoint(includePersonal: true));
        $body = json_decode($envelope->json, true);

        $this->assertSame(strlen($envelope->json), $envelope->bytes);
        $this->assertSame(['id', 'type', 'timestamp', 'version', 'data'], array_keys($body));
        $this->assertSame('msg_abc', $body['id']);
        $this->assertSame('submission.created', $body['type']);
        $this->assertSame('2026-11-08T10:00:00Z', $body['timestamp']);
        $this->assertSame(1, $body['version']);
        $this->assertSame([
            'id' => 42, 'reference' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'status' => 'answered', 'status_id' => 2,
            'store_id' => 3, 'store_code' => 'uk', 'created_at' => '2026-11-07T09:30:00Z', 'updated_at' => null,
            'customer_id' => 5,
        ], $body['data']['submission']);
        $this->assertSame(['id' => 7, 'identifier' => 'contact', 'title' => 'Contact us'], $body['data']['form']);
        $this->assertSame(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Widget'], $body['data']['product']);
        $this->assertArrayNotHasKey('change', $body['data']);
        $this->assertStringNotContainsString('127.0.0.1', $envelope->json);
        $this->assertStringNotContainsString('email_hash', $envelope->json);
        $this->assertStringNotContainsString('https:\/\/', $envelope->json);
    }

    public function testItLeavesOutPersonalFieldsAndFieldsMissingFromTheSchemaUnlessTheEndpointIncludesPersonalData(): void
    {
        $body = json_decode($this->build($this->endpoint())->json, true);

        $this->assertSame(['topic' => 'sales'], $body['data']['values']);
        $this->assertSame(['topic'], array_column($body['data']['fields'], 'name'));
        $this->assertSame([], array_column($body['data']['files'], 'field'));
        $this->assertArrayNotHasKey('customer_id', $body['data']['submission']);

        $all = json_decode($this->build($this->endpoint(includePersonal: true))->json, true);
        $this->assertSame(['topic' => 'sales', 'full_name' => 'Ada Lovelace', 'old_field' => 'legacy'], $all['data']['values']);
        $this->assertSame(5, $all['data']['submission']['customer_id']);
    }

    public function testItTreatsEveryFieldAsPersonalWhenTheFormIsGone(): void
    {
        $body = json_decode($this->build($this->endpoint(), formGone: true)->json, true);

        $this->assertSame([], (array)$body['data']['values']);
        $this->assertSame(['id' => 7, 'identifier' => '', 'title' => ''], $body['data']['form']);
    }

    public function testItIncludesOnlyTheSelectedFields(): void
    {
        $endpoint = $this->endpoint(includePersonal: true, fieldMode: 'selected', fields: ['full_name', 'cv', 'nonexistent']);
        $body = json_decode($this->build($endpoint)->json, true);

        $this->assertSame(['full_name' => 'Ada Lovelace'], $body['data']['values']);
        $this->assertSame(['cv'], array_column($body['data']['files'], 'field'));

        $noPersonal = json_decode($this->build($this->endpoint(fieldMode: 'selected', fields: ['full_name', 'topic']))->json, true);
        $this->assertSame(['topic' => 'sales'], $noPersonal['data']['values']);
    }

    public function testItSendsFileMetadataWithoutLinksOrStoragePaths(): void
    {
        $envelope = $this->build($this->endpoint(includePersonal: true));
        $body = json_decode($envelope->json, true);

        $this->assertSame(
            [['field' => 'cv', 'name' => 'cv.pdf', 'size' => 1024, 'mime' => 'application/pdf']],
            $body['data']['files']
        );
        $this->assertArrayNotHasKey('cv', $body['data']['values']);
        $this->assertStringNotContainsString('furan_formbuilder/7', $envelope->json);
        $this->assertStringNotContainsString('sha256', $envelope->json);
    }

    public function testItListsAnUnansweredFileFieldWithoutAFileEntry(): void
    {
        $body = json_decode($this->build(
            $this->endpoint(includePersonal: true),
            payload: [['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => '']]
        )->json, true);

        $this->assertSame([], $body['data']['files']);
        $this->assertSame([['name' => 'cv', 'label' => 'CV', 'type' => 'file']], $body['data']['fields']);
    }

    public function testItSendsTheOldAndNewStatusInStatusChangedPayloads(): void
    {
        $body = json_decode($this->build(
            $this->endpoint(),
            type: 'submission.status_changed',
            eventData: ['old' => 0, 'new' => 2, 'source' => 'customer']
        )->json, true);

        $this->assertSame('new', $body['data']['change']['old_status']);
        $this->assertSame('answered', $body['data']['change']['new_status']);
    }

    public function testItTreatsAnEmailFieldMarkedNotPersonalAsPersonalForWebhooks(): void
    {
        $this->schema = $this->schemaOf([['type' => 'email', 'name' => 'mail', 'personal' => false]]);
        $body = json_decode($this->build(
            $this->endpoint(),
            payload: [['name' => 'mail', 'label' => 'Mail', 'type' => 'email', 'value' => 'a@b.test']]
        )->json, true);

        $this->assertSame([], (array)$body['data']['values']);
    }

    public function testItTreatsAFieldWithAPersonalAutocompleteMarkedNotPersonalAsPersonalForWebhooks(): void
    {
        $this->schema = $this->schemaOf([['type' => 'text', 'name' => 'who', 'personal' => false, 'autocomplete' => 'given-name']]);
        $body = json_decode($this->build(
            $this->endpoint(),
            payload: [['name' => 'who', 'label' => 'Who', 'type' => 'text', 'value' => 'Ada']]
        )->json, true);

        $this->assertSame([], (array)$body['data']['values']);
    }

    public function testItTreatsAFieldWhoseStoredSubmissionTypeIsEmailAsPersonalAfterTheSchemaTypeChanged(): void
    {
        $this->schema = $this->schemaOf([['type' => 'text', 'name' => 'mail', 'personal' => false]]);
        $body = json_decode($this->build(
            $this->endpoint(),
            payload: [['name' => 'mail', 'label' => 'Mail', 'type' => 'email', 'value' => 'a@b.test']]
        )->json, true);

        $this->assertSame([], (array)$body['data']['values']);
    }

    public function testItSendsAPlainTextFieldMarkedNotPersonal(): void
    {
        $this->schema = $this->schemaOf([['type' => 'text', 'name' => 'topic', 'personal' => false]]);
        $body = json_decode($this->build(
            $this->endpoint(),
            payload: [['name' => 'topic', 'label' => 'Topic', 'type' => 'text', 'value' => 'sales']]
        )->json, true);

        $this->assertSame(['topic' => 'sales'], $body['data']['values']);
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function schemaOf(array $fields): array
    {
        return ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'items' => array_map(
            static fn(array $f): array => ['kind' => 'field', 'label' => $f['name']] + $f,
            $fields
        )]]];
    }

    public function testItAddsTheStatusChangeFromTheDeliveryEventData(): void
    {
        $body = json_decode($this->build(
            $this->endpoint(),
            type: 'submission.status_changed',
            eventData: ['old' => 0, 'new' => 2, 'source' => 'admin', 'extra' => 'ignored']
        )->json, true);

        $this->assertSame(['old_status' => 'new', 'new_status' => 'answered', 'source' => 'admin'], $body['data']['change']);
    }

    public function testItRefusesAPayloadLargerThanTheCap(): void
    {
        $payload = [['name' => 'topic', 'label' => 'Topic', 'type' => 'text', 'value' => str_repeat('a', 262144)]];

        $this->expectException(PayloadTooLargeException::class);
        $this->build($this->endpoint(), payload: $payload);
    }

    public function testItSendsAFixedSyntheticPayloadForTheTestEvent(): void
    {
        $body = json_decode($this->builder()->build(
            $this->endpoint(includePersonal: true),
            'msg_t',
            'webhook.test',
            '2026-11-08T10:00:00Z',
            null,
            []
        )->json, true);

        $this->assertSame('webhook.test', $body['type']);
        $this->assertSame(['test' => true], array_diff_key($body['data'], ['message' => 1]));
    }

    public function testItRefusesAMissingSubmissionForARealEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->build($this->endpoint(), 'msg_t', 'submission.created', '2026-11-08T10:00:00Z', null, []);
    }

    /** @param string[] $fields */
    private ?array $schema = null;

    private function endpoint(bool $includePersonal = false, string $fieldMode = 'all', array $fields = []): EndpointView
    {
        return new EndpointView(
            1, 'CRM', 'https://example.test/hook', true, 'store', [3], true, [], ['submission.created'],
            $fieldMode, $fields, $includePersonal, false, null, null, null, 0, null
        );
    }

    private function build(
        EndpointView $endpoint,
        string $type = 'submission.created',
        array $eventData = [],
        ?array $payload = null,
        bool $formGone = false
    ): \Furan\FormBuilder\Model\Webhook\Envelope {
        return $this->builder($formGone)->build(
            $endpoint,
            'msg_abc',
            $type,
            '2026-11-08T10:00:00Z',
            $this->submission($payload ?? self::PAYLOAD),
            $eventData
        );
    }

    private function builder(bool $formGone = false): PayloadBuilder
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);
        $form->method('getIdentifier')->willReturn('contact');
        $form->method('getTitle')->willReturn('Contact us');
        $form->method('getSchema')->willReturn($this->schema ?? self::SCHEMA);
        $forms = $this->createStub(FormRepositoryInterface::class);
        if ($formGone) {
            $forms->method('getById')->willThrowException(new NoSuchEntityException());
        } else {
            $forms->method('getById')->willReturn($form);
        }
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('uk');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $pool = FieldPools::adminView();

        return new PayloadBuilder($forms, new PersonalData($pool), $pool, $stores);
    }

    private function submission(array $payload): SubmissionInterface
    {
        $s = $this->createStub(SubmissionInterface::class);
        $s->method('getId')->willReturn(42);
        $s->method('getFormId')->willReturn(7);
        $s->method('getReference')->willReturn('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
        $s->method('getStatus')->willReturn(2);
        $s->method('getStoreId')->willReturn(3);
        $s->method('getCreatedAt')->willReturn('2026-11-07 09:30:00');
        $s->method('getUpdatedAt')->willReturn(null);
        $s->method('getCustomerId')->willReturn(5);
        $s->method('getPayload')->willReturn($payload);
        $s->method('getContext')->willReturn(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Widget', 'url' => 'https://x.test/p', 'price' => 9.5]);
        $s->method('getRemoteIp')->willReturn('127.0.0.1');
        $s->method('getEmailHash')->willReturn('hash');
        return $s;
    }
}
