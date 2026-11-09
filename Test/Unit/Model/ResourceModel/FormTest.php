<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\ResourceModel;

use Furan\FormBuilder\Model\FieldGroup\GroupUsage;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Validation\SavedRuleUsage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class FormTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private Select&Stub $select;
    private FormResource $resource;
    private SchemaMigrator $migrator;
    private GroupUsage $groupUsage;
    private SavedRuleUsage $ruleUsage;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(
        ?AdapterInterface $connection = null,
        ?SchemaMigrator $migrator = null,
        ?Select $select = null,
        ?GroupUsage $groupUsage = null,
        ?SavedRuleUsage $ruleUsage = null
    ): void {
        $this->groupUsage = $groupUsage ?? $this->createStub(GroupUsage::class);
        $this->ruleUsage = $ruleUsage ?? $this->createStub(SavedRuleUsage::class);
        $this->migrator = $migrator ?? $this->createStub(SchemaMigrator::class);
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $this->select = $select ?? $this->createStub(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($this->select);

        $resources = $this->createStub(ResourceConnection::class);
        $resources->method('getConnection')->willReturn($this->connection);
        $resources->method('getTableName')->willReturnArgument(0);
        $context = $this->createStub(Context::class);
        $context->method('getResources')->willReturn($resources);

        $this->resource = new FormResource($context, new \Magento\Framework\Serialize\Serializer\Json(), $this->migrator, $this->groupUsage, $this->ruleUsage);
    }

    private function invoke(string $method, Form $form): void
    {
        (new \ReflectionMethod($this->resource, $method))->invoke($this->resource, $form);
    }

    private function form(): Form&Stub
    {
        return $this->configureForm($this->createStub(Form::class));
    }

    private function assertedForm(): Form&MockObject
    {
        return $this->configureForm($this->createMock(Form::class));
    }

    /**
     * @template T of Form&Stub
     * @param T $form
     * @return T
     */
    private function configureForm(Form&Stub $form): Form&Stub
    {
        $form->method('getId')->willReturn(5);
        return $form;
    }

    public function testItSavesStoreIdsIntoTheStoreLinkTable(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $form = $this->form();
        $form->method('hasData')->willReturnCallback(fn(string $key) => $key === 'store_ids');
        $form->method('getStoreIds')->willReturn([0, 2]);

        $connection->expects($this->once())->method('delete')
            ->with('furan_formbuilder_form_store', ['form_id = ?' => 5]);
        $connection->expects($this->once())->method('insertMultiple')
            ->with('furan_formbuilder_form_store', [
                ['form_id' => 5, 'store_id' => 0],
                ['form_id' => 5, 'store_id' => 2],
            ]);

        $this->invoke('_afterSave', $form);
    }

    public function testItRecordsTheFieldGroupsTheSavedSchemaCameFrom(): void
    {
        $usage = $this->createMock(GroupUsage::class);
        $this->fixture(groupUsage: $usage);
        $schema = ['pages' => [['items' => [['kind' => 'field', 'source_group' => 4]]]]];
        $form = $this->form();
        $form->method('hasData')->willReturnCallback(fn(string $key) => $key === 'schema_json');
        $form->method('getSchema')->willReturn($schema);

        $usage->expects($this->once())->method('sync')->with(5, $schema);

        $this->invoke('_afterSave', $form);
    }

    public function testItRecordsTheSavedRulesTheSavedSchemaUses(): void
    {
        $usage = $this->createMock(SavedRuleUsage::class);
        $this->fixture(ruleUsage: $usage);
        $schema = ['pages' => [['items' => [['kind' => 'field', 'rules' => [['rule' => 'preset', 'value' => 'saved:ref']]]]]]];
        $form = $this->form();
        $form->method('hasData')->willReturnCallback(fn(string $key) => $key === 'schema_json');
        $form->method('getSchema')->willReturn($schema);

        $usage->expects($this->once())->method('sync')->with(5, $schema);

        $this->invoke('_afterSave', $form);
    }

    public function testItLeavesTheGroupAndRuleLinksAloneWhenTheSchemaWasNotLoaded(): void
    {
        $usage = $this->createMock(GroupUsage::class);
        $rules = $this->createMock(SavedRuleUsage::class);
        $this->fixture(groupUsage: $usage, ruleUsage: $rules);
        $form = $this->form();
        $form->method('hasData')->willReturn(false);

        $usage->expects($this->never())->method('sync');
        $rules->expects($this->never())->method('sync');

        $this->invoke('_afterSave', $form);
    }

    public function testItKeepsTheIndexedColumnsInSyncWithTheSettingsOnSave(): void
    {
        $form = $this->form();
        $form->method('hasData')->willReturnCallback(fn(string $key) => $key === 'settings_json');
        $form->method('getSettings')->willReturn(['open_from' => '2026-07-01T09:00:00Z', 'close_at' => '']);
        $written = [];
        $form->method('setData')->willReturnCallback(function ($key, $value = null) use (&$written, $form) {
            $written[$key] = $value;
            return $form;
        });

        $this->invoke('_beforeSave', $form);

        $this->assertSame('2026-07-01 09:00:00', $written['open_from']);
        $this->assertNull($written['close_at']);
    }

    public function testItLeavesTheIndexedColumnsAloneWhenSettingsWereNotLoaded(): void
    {
        $form = $this->assertedForm();
        $form->method('hasData')->willReturn(false);
        $form->expects($this->never())->method('setData');

        $this->invoke('_beforeSave', $form);
    }

    public function testItKeepsStoreLinksWhenSavingAFormWhoseStoreIdsWereNotLoaded(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $form = $this->form();
        $form->method('hasData')->willReturn(false);

        $connection->expects($this->never())->method('delete');
        $connection->expects($this->never())->method('insertMultiple');

        $this->invoke('_afterSave', $form);
    }

    public function testItKeepsAnExplicitlyEmptyStoreListEmpty(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $form = $this->form();
        $form->method('hasData')->willReturnCallback(fn(string $key) => $key === 'store_ids');
        $form->method('getStoreIds')->willReturn([]);

        $connection->expects($this->once())->method('delete');
        $connection->expects($this->never())->method('insertMultiple');

        $this->invoke('_afterSave', $form);
    }

    public function testItLoadsStoreIdsAfterLoad(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $form = $this->assertedForm();
        $connection->expects($this->once())->method('fetchCol')
            ->with($this->select, ['form_id' => 5])->willReturn(['0', '3']);
        $this->connection->method('fetchPairs')->willReturn([]);
        $form->expects($this->once())->method('setStoreIds')->with([0, 3]);

        $this->invoke('_afterLoad', $form);
    }

    public function testItLooksUpAFormIdByIdentifierRegardlessOfStatus(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $this->fixture(connection: $connection, select: $select);
        $select->expects($this->once())->method('where')->with('identifier = :identifier');
        $connection->method('fetchOne')->with($this->select, ['identifier' => 'contact'])
            ->willReturn('9');

        $this->assertSame(9, $this->resource->getIdByIdentifier('contact'));
    }

    public function testItReturnsNullWhenNoFormHasTheIdentifier(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $this->assertNull($this->resource->getIdByIdentifier('missing'));
    }

    public function testItSavesTranslationsPerStoreAndSkipsEmptyOverlays(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $form = $this->form();
        $form->method('hasData')->willReturnCallback(fn(string $key) => $key === 'translations');
        $form->method('getTranslations')->willReturn([2 => ['form' => ['submit_label' => ['t' => 'Envoyer', 's' => 'Send']]], 3 => []]);
        $connection->expects($this->once())->method('delete')
            ->with('furan_formbuilder_form_translation', ['form_id = ?' => 5]);
        $connection->expects($this->once())->method('insertMultiple')
            ->with('furan_formbuilder_form_translation', [[
                'form_id' => 5,
                'store_id' => 2,
                'content' => '{"form":{"submit_label":{"t":"Envoyer","s":"Send"}}}',
            ]]);

        $this->invoke('_afterSave', $form);
    }

    public function testItLoadsTranslationsAndIgnoresUnreadableRows(): void
    {
        $form = $this->assertedForm();
        $this->connection->method('fetchCol')->willReturn([]);
        $this->connection->method('fetchPairs')->willReturn(['2' => '{"form":{"submit_label":{"t":"Envoyer","s":"Send"}}}', '3' => '{broken']);
        $form->expects($this->once())->method('setTranslations')
            ->with([2 => ['form' => ['submit_label' => ['t' => 'Envoyer', 's' => 'Send']]]]);

        $this->invoke('_afterLoad', $form);
    }

    public function testItLoadsTheTranslationsOfAPageOfFormsInOneQuery(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->once())->method('where')->with('form_id IN (?)', [4, 9])->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects($this->once())->method('fetchAll')->willReturn([
            ['form_id' => '4', 'store_id' => '2', 'content' => '{"form":{"title":{"t":"Kontakt","s":"Contact"}}}'],
            ['form_id' => '9', 'store_id' => '3', 'content' => '{broken'],
            ['form_id' => '9', 'store_id' => '5', 'content' => '[]'],
        ]);
        $this->fixture(connection: $connection, select: $select);

        $this->assertSame(
            [4 => [2 => ['form' => ['title' => ['t' => 'Kontakt', 's' => 'Contact']]]]],
            $this->resource->lookupTranslationsFor([4, 9])
        );
    }

    public function testItMigratesTheSchemaWhenItIsLoaded(): void
    {
        $migrator = $this->createMock(SchemaMigrator::class);
        $this->fixture(migrator: $migrator);
        $this->connection->method('fetchCol')->willReturn([]);
        $this->connection->method('fetchPairs')->willReturn([]);
        $migrator->method('upgradeJson')->with('{"version":1}')->willReturn('{"version":2}');
        $form = $this->assertedForm();
        $form->method('hasData')->willReturnCallback(fn(string $key) => $key === 'schema_json');
        $form->method('getData')->willReturnCallback(fn($key = '') => $key === 'schema_json' ? '{"version":1}' : null);
        $form->expects($this->once())->method('setData')->with('schema_json', '{"version":2}');

        $this->invoke('_afterLoad', $form);
    }

    public function testItDoesNotTouchTheSchemaWhenItWasNotSelected(): void
    {
        $migrator = $this->createMock(SchemaMigrator::class);
        $this->fixture(migrator: $migrator);
        $this->connection->method('fetchCol')->willReturn([]);
        $this->connection->method('fetchPairs')->willReturn([]);
        $migrator->expects($this->never())->method('upgradeJson');
        $form = $this->form();
        $form->method('hasData')->willReturn(false);

        $this->invoke('_afterLoad', $form);
    }

    public function testItSetsOnlyTheActiveFlagOfTheGivenForms(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('update')
            ->with('furan_formbuilder_form', ['is_active' => 0], ['form_id IN (?)' => [4, 9]])
            ->willReturn(2);

        $this->assertSame(2, $this->resource->setActive([4, 9, 4], false));
    }

    public function testItUpdatesTheActiveFlagInChunksAndSkipsAnEmptySelection(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $chunks = [];
        $connection->expects($this->exactly(2))->method('update')
            ->willReturnCallback(function (string $table, array $bind, array $where) use (&$chunks): int {
                $this->assertSame(['is_active' => 1], $bind);
                $chunks[] = count($where['form_id IN (?)']);
                return count($where['form_id IN (?)']);
            });

        $this->assertSame(0, $this->resource->setActive([], true));
        $this->assertSame(1500, $this->resource->setActive(range(1, 1500), true));
        $this->assertSame([1000, 500], $chunks);
    }
}
