<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Stats\FreeTextReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class FreeTextReaderTest extends TestCase
{
    /** @var array<int, array{string, array}> */
    private array $calls = [];
    private array $rows = [];

    private array $window = [];
    private array $windowCalls = [];

    private function reader(?\Furan\FormBuilder\Model\FieldType\Pool $pool = null): FreeTextReader
    {
        $pool ??= \Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) {
            $this->calls[] = [$sql, $bind];
            return $this->rows;
        });
        $connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind) {
            $this->windowCalls[] = [$sql, $bind];
            return array_shift($this->window) ?? [];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new FreeTextReader($resource, new Json(), new PersonalData($pool), $this->createStub(\Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex::class), $pool);
    }

    private function row(int $id, array $values): array
    {
        $payload = [];
        foreach ($values as $name => $value) {
            $payload[] = ['name' => $name, 'label' => $name, 'type' => 'text', 'value' => $value];
        }
        return ['submission_id' => (string)$id, 'payload_json' => json_encode($payload)];
    }

    public function testItListsTheLatestFreeTextAnswersScanningABoundedNumberOfSubmissionsPerPage(): void
    {
        $fields = [
            ['name' => 'comment', 'type' => 'textarea'],
            ['name' => 'nick', 'type' => 'text'],
        ];
        $this->rows = [$this->row(9, ['comment' => 'Nice', 'nick' => '']), $this->row(8, ['comment' => '', 'nick' => 'bob'])];
        $this->window = [['9', '8', '7']];

        $result = $this->reader()->read(5, $fields, ['store_id' => 2, 'customer_group_id' => 0]);

        $this->assertCount(1, $this->calls, 'one shared scan');
        [$sql, $bind] = $this->calls[0];
        $this->assertStringContainsString('submission_id IN (9,8,7)', $sql);
        $this->assertStringContainsString('ORDER BY submission_id DESC', $sql);
        $this->assertStringContainsString('store_id = ?', $sql);
        $this->assertStringContainsString('customer_group_id = ?', $sql);
        $this->assertSame([2, 0], $bind);
        $this->assertStringContainsString('WHERE form_id = ? ORDER BY submission_id DESC LIMIT 500', $this->windowCalls[0][0]);
        $this->assertSame([5], $this->windowCalls[0][1]);
        $this->assertSame([['id' => 9, 'text' => 'Nice']], $result['comment']['answers']);
        $this->assertSame([['id' => 8, 'text' => 'bob']], $result['nick']['answers']);
        $this->assertNull($result['comment']['cursor'], 'scan reached the end');
    }

    public function testItContinuesBelowTheCursorForOneFieldOnly(): void
    {
        $fields = [['name' => 'comment', 'type' => 'text'], ['name' => 'nick', 'type' => 'text']];
        $this->rows = array_map(fn (int $id) => $this->row($id, ['comment' => 'c' . $id]), range(900, 401));

        $result = $this->reader()->read(5, $fields, [], 'comment', 1000);

        [$sql, $bind] = $this->calls[0];
        $this->assertStringContainsString('submission_id < ?', $sql);
        $this->assertSame([5, 1000], $bind);
        $this->assertSame(['comment'], array_keys($result));
        $this->assertCount(20, $result['comment']['answers']);
        $this->assertSame(881, $result['comment']['cursor'], 'resumes after the last row consumed');
    }

    public function testItOffersTheNextCursorWhenAFullScanFoundFewerAnswers(): void
    {
        $this->rows = array_map(fn (int $id) => $this->row($id, ['comment' => '']), range(600, 101));

        $result = $this->reader()->read(5, [['name' => 'comment', 'type' => 'text']]);

        $this->assertSame([], $result['comment']['answers']);
        $this->assertSame(101, $result['comment']['cursor']);
    }

    public function testItNeverListsAnswersOfFieldsFlaggedAsPersonalDataOrOfNonTextTypes(): void
    {
        $fields = [
            ['name' => 'a', 'type' => 'text', 'personal' => true],
            ['name' => 'b', 'type' => 'text', 'autocomplete' => 'given-name'],
            ['name' => 'c', 'type' => 'email'],
            ['name' => 'd', 'type' => 'hidden'],
            ['name' => 'e', 'type' => 'file'],
            ['name' => 'f', 'type' => 'tel'],
            ['name' => 'g', 'type' => 'url'],
            ['name' => 'h', 'type' => 'suggest', 'personal' => false],
        ];
        $this->rows = [$this->row(1, ['a' => 'x', 'b' => 'x', 'c' => 'x', 'd' => 'x', 'e' => 'x', 'f' => 'x', 'g' => 'x', 'h' => 'ok'])];

        $result = $this->reader()->read(5, $fields);

        $this->assertSame(['h'], array_keys($result));
        $this->assertSame('ok', $result['h']['answers'][0]['text']);
    }

    public function testItRunsNoQueryWhenNoFieldIsEligible(): void
    {
        $this->assertSame([], $this->reader()->read(5, [['name' => 'c', 'type' => 'email']]));
        $this->assertSame([], $this->calls);
    }

    public function testItBoundsAFilteredScanToAWindowOfTheLatestIdsAndContinuesBelowIt(): void
    {
        $pages = FreeTextReader::WINDOW / FreeTextReader::SCAN_LIMIT;
        for ($p = 0; $p < $pages + 1; $p++) {
            $top = 9000 - $p * 500;
            $this->window[] = range($top, $top - 499);
        }
        $this->rows = [$this->row(8990, ['comment' => 'Rare store'])];

        $result = $this->reader()->read(5, [['name' => 'comment', 'type' => 'textarea']], ['store_id' => 9], null, 9001);

        $this->assertCount($pages, $this->windowCalls, 'stops after WINDOW ids');
        $this->assertSame([5, 9001], $this->windowCalls[0][1]);
        $this->assertSame([5, 8501], $this->windowCalls[1][1]);
        $this->assertSame(9000 - FreeTextReader::WINDOW + 1, $result['comment']['cursor']);
        $this->assertSame(8990, $result['comment']['answers'][0]['id']);
    }

    public function testItListsFreeTextAnswersForTypesImplementingFreeTextTypeInterface(): void
    {
        $pool = new \Furan\FormBuilder\Model\FieldType\Pool([
            'stat' => new \Furan\FormBuilder\Test\Unit\Helper\StatsType(null, true),
        ]);
        $this->rows = [$this->row(3, ['a' => 'hello', 'b' => 'no'])];

        $result = $this->reader($pool)->read(5, [
            ['name' => 'a', 'type' => 'stat'],
            ['name' => 'b', 'type' => 'unknown'],
        ]);

        $this->assertSame(['a'], array_keys($result));
        $this->assertSame([['id' => 3, 'text' => 'hello']], $result['a']['answers']);
    }

    public function testItHidesPersonalByDefaultAnswersFromAnalyticsFreeTextEvenWhenUnticked(): void
    {
        $fields = [
            ['name' => 'a', 'type' => 'text', 'autocomplete' => 'given-name', 'personal' => false],
            ['name' => 'b', 'type' => 'text', 'personal' => false],
        ];
        $this->rows = [$this->row(1, ['a' => 'secret', 'b' => 'ok'])];

        $this->assertSame(['b'], array_keys($this->reader()->read(5, $fields)));
    }
}
