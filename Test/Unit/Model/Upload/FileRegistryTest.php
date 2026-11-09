<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\Upload\FileRegistry;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class FileRegistryTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private FileRegistry $registry;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->registry = new FileRegistry($resource);
    }

    public function testItReleasesAReplacedFileToTheCleanupCron(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with(
                'furan_formbuilder_file',
                ['submission_id' => null],
                ['submission_id = ?' => 9, 'path IN (?)' => ['a/1', 'a/2']]
            )
            ->willReturn(2);

        $this->assertSame(2, $this->registry->detach(9, ['a/1', 'a/2']));
    }

    public function testItDetachesNothingForNoPaths(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertSame(0, $this->registry->detach(9, []));
    }
}
