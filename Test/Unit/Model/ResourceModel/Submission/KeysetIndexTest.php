<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\ResourceModel\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class KeysetIndexTest extends TestCase
{
    private function index(array $list): KeysetIndex
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('getIndexList')->with('pfx_furan_formbuilder_submission')->willReturn($list);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);
        return new KeysetIndex($resource);
    }

    public function testItFindsTheFormKeysetIndexByItsColumnsSoATablePrefixDoesNotMatter(): void
    {
        $index = $this->index([
            'PRIMARY' => ['COLUMNS_LIST' => ['submission_id']],
            'PFX_IDX_FORM_IS_READ' => ['COLUMNS_LIST' => ['form_id', 'is_read']],
            'PFX_IDX_1A2B3C' => ['COLUMNS_LIST' => ['form_id', 'submission_id']],
        ]);

        $this->assertSame(' FORCE INDEX (`PFX_IDX_1A2B3C`)', $index->forForm());
        $this->assertSame(' FORCE INDEX (`PFX_IDX_1A2B3C`)', $index->forForm(), 'looked up once');
    }

    public function testItGivesNoHintWhenTheIndexIsMissing(): void
    {
        $this->assertSame('', $this->index(['PRIMARY' => ['COLUMNS_LIST' => ['submission_id']]])->forForm());
    }
}
