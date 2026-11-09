<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Model\Validation\SavedRuleUsage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SavedRuleUsageTest extends TestCase
{
    private Mysql&MockObject $connection;
    /** @var list<array{string, array<mixed>}> */
    private array $calls = [];

    private function usage(): SavedRuleUsage
    {
        $this->connection = $this->createMock(Mysql::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'where', 'order', 'group'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->calls[] = [$method, $args];
                return $select;
            });
        }
        $this->connection->method('select')->willReturn($select);
        return new SavedRuleUsage($resource);
    }

    /** @return list<array<mixed>> */
    private function called(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $method)
        ));
    }

    /** @param list<array<mixed>> $rules */
    private static function field(array $rules): array
    {
        return ['kind' => 'field', 'name' => 'f', 'rules' => $rules];
    }

    public function testItCollectsTheSavedRuleCodesOfFieldsAndSectionChildrenOnce(): void
    {
        $schema = ['pages' => [
            ['items' => [
                self::field([['rule' => 'preset', 'value' => 'saved:ref'], ['rule' => 'preset', 'value' => 'uk_postcode']]),
                ['kind' => 'section', 'items' => [self::field([['rule' => 'preset', 'value' => 'saved:zip']])]],
            ]],
            ['items' => [
                self::field([['rule' => 'preset', 'value' => 'saved:ref'], ['rule' => 'regex', 'value' => 'saved:x']]),
            ]],
        ]];

        $this->assertSame(['ref', 'zip'], SavedRuleUsage::codes($schema));
    }

    public function testItIgnoresMalformedSchemasAndRules(): void
    {
        $this->assertSame([], SavedRuleUsage::codes([]));
        $this->assertSame([], SavedRuleUsage::codes(['pages' => 'x']));
        $this->assertSame([], SavedRuleUsage::codes(['pages' => [['items' => [
            self::field(['x', ['rule' => 'preset', 'value' => ['saved:a']], ['rule' => 'preset', 'value' => 'saved:']]),
            ['kind' => 'field', 'rules' => 'saved:b'],
        ]]]]));
    }

    public function testSyncReplacesTheFormsLinksWithTheRulesThatExist(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('delete')
            ->with(SavedRuleUsage::TABLE, ['form_id = ?' => 12]);
        $this->connection->expects($this->once())->method('insertFromSelect')
            ->with($this->isInstanceOf(Select::class), SavedRuleUsage::TABLE, ['code', 'form_id'], AdapterInterface::INSERT_IGNORE)
            ->willReturn('INSERT');
        $this->connection->expects($this->once())->method('query')->with('INSERT');

        $usage->sync(12, ['pages' => [['items' => [self::field([['rule' => 'preset', 'value' => 'saved:ref']])]]]]);

        $this->assertContains(['code IN (?)', ['ref']], $this->called('where'));
    }

    public function testSyncOnlyClearsTheLinksWhenTheFormUsesNoSavedRule(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('delete');
        $this->connection->expects($this->never())->method('insertFromSelect');

        $usage->sync(12, ['pages' => [['items' => [self::field([])]]]]);
    }

    public function testItListsTheFormsUsingARuleThroughTheLinkTable(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([
            ['form_id' => '3', 'title' => 'Contact'],
            ['form_id' => '8', 'title' => 'Returns'],
        ]);

        $this->assertSame([['id' => 3, 'title' => 'Contact'], ['id' => 8, 'title' => 'Returns']], $usage->forms('ref'));
        $this->assertContains(['link.code = ?', 'ref'], $this->called('where'));
        $this->assertContains(['link.form_id ASC'], $this->called('order'));
    }

    public function testItCountsFormsPerRuleForTheGivenCodes(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('fetchPairs')->willReturn(['ref' => '2', 'zip' => '1']);

        $this->assertSame(['ref' => 2, 'zip' => 1], $usage->counts(['ref', 'zip', 'unused']));
        $this->assertContains(['code IN (?)', ['ref', 'zip', 'unused']], $this->called('where'));
        $this->assertContains(['code'], $this->called('group'));
        $this->assertSame([], $usage->counts([]));
    }
}
