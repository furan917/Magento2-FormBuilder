<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Draft;

use Furan\FormBuilder\Model\Draft\DraftStore;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DraftStoreTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private DraftStore $store;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?AdapterInterface $connection = null): void
    {
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->store = new DraftStore($resource, new Json(), FieldPools::withFiles());
    }

    private function schema(): array
    {
        $field = static fn (string $name, string $type, array $extra = []): array => array_merge(
            ['kind' => 'field', 'type' => $type, 'name' => $name],
            $extra
        );

        return ['pages' => [['items' => [
            $field('first_name', 'text'),
            $field('upload', 'file'),
            $field('tracking', 'hidden'),
            $field('secret', 'text', ['draft' => false]),
            $field('email', 'email', ['prefill' => 'customer.email', 'locked' => true]),
            ['kind' => 'section', 'items' => [$field('city', 'text'), $field('interests', 'checkboxes')]],
        ]]]];
    }

    public function testItNeverStoresFileInputsOrTheRecaptchaResponseInDrafts(): void
    {
        $values = $this->store->sanitize($this->schema(), [
            'first_name' => 'Ann',
            'upload' => 'x.pdf',
            'g-recaptcha-response' => 'tok',
            'form_key' => 'k',
            'ffb_token' => 't',
            'ffb_website' => '',
            'unknown' => 'nope',
        ]);

        $this->assertSame(['first_name' => 'Ann'], $values);
    }

    public function testItDropsLockedHiddenAndDraftDisabledFieldsFromASavedDraft(): void
    {
        $values = $this->store->sanitize($this->schema(), [
            'first_name' => 'Ann',
            'tracking' => 'abc',
            'secret' => 'shh',
            'email' => 'a@b.c',
            'city' => 'Paris',
            'interests' => ['a', 'b'],
        ]);

        $this->assertSame(['first_name' => 'Ann', 'city' => 'Paris', 'interests' => ['a', 'b']], $values);
    }

    public function testItKeepsOnlyShallowValues(): void
    {
        $values = $this->store->sanitize($this->schema(), [
            'first_name' => ['street' => ['a', ['deep']]],
            'city' => ['street' => ['a', 'b'], 'city' => 'X'],
            'interests' => [['nested']],
        ]);

        $this->assertSame(['city' => ['street' => ['a', 'b'], 'city' => 'X']], $values);
    }

    public function testItUpsertsTheDraftOnThePrimaryKey(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('insertOnDuplicate')->with(
            'furan_formbuilder_draft',
            $this->callback(fn(array $row): bool => $row['form_id'] === 5 && $row['customer_id'] === 7 && $row['store_id'] === 2
                && $row['payload_json'] === '{"a":"b"}'),
            ['payload_json', 'updated_at']
        );

        $this->store->save(5, 7, 2, ['a' => 'b']);
    }

    public function testItLoadsADraftWithEpochMilliseconds(): void
    {
        $select = $this->createStub(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(['payload_json' => '{"a":"b"}', 'updated' => '1700000000']);

        $this->assertSame(
            ['values' => ['a' => 'b'], 'updatedAt' => 1700000000000],
            $this->store->find(5, 7, 2)
        );
    }

    public function testItReturnsNullWhenThereIsNoDraft(): void
    {
        $select = $this->createStub(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);

        $this->assertNull($this->store->find(5, 7, 2));
    }

    public function testItClearsADraftByPrimaryKey(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('delete')->with(
            'furan_formbuilder_draft',
            ['form_id = ?' => 5, 'customer_id = ?' => 7, 'store_id = ?' => 2]
        );

        $this->store->clear(5, 7, 2);
    }

    public function testItNeverStoresCustomFileTypesInDrafts(): void
    {
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'doc', 'name' => 'attachment'],
            ['kind' => 'field', 'type' => 'text', 'name' => 'note'],
        ]]]];

        $this->assertSame(['note' => 'x'], $this->store->sanitize($schema, ['attachment' => 'a.pdf', 'note' => 'x']));
    }

    public function testItNeverSavesANewsletterAnswerInADraftEvenWhenTheStoredFieldAllowsIt(): void
    {
        $bridge = $this->createStub(\Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class);
        $pool = new \Furan\FormBuilder\Model\FieldType\Pool([
            'text' => new \Furan\FormBuilder\Model\FieldType\Text(),
            'newsletter' => new \Furan\FormBuilder\Model\FieldType\Newsletter($bridge),
        ]);
        $store = new DraftStore($this->createStub(ResourceConnection::class), new Json(), $pool);
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'name'],
            ['kind' => 'field', 'type' => 'newsletter', 'name' => 'news', 'draft' => true],
        ]]]];

        $this->assertSame(['name' => 'Ann'], $store->sanitize($schema, ['name' => 'Ann', 'news' => 'yes']));
    }
}
