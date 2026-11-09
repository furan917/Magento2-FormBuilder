<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Console\Command;

use Furan\FormBuilder\Console\Command\RebuildStatsCommand;
use Furan\FormBuilder\Model\Stats\Rebuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RebuildStatsCommandTest extends TestCase
{
    private Rebuilder&Stub $rebuilder;
    private CommandTester $tester;
    /** @var int[] */
    private array $formIds = [];
    /** @var array<int, array> */
    private array $selects = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?Rebuilder $rebuilder = null): void
    {
        $this->rebuilder = $rebuilder ?? $this->createStub(Rebuilder::class);
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind = []): array {
            $this->selects[] = [$sql, $bind];
            return array_values(array_filter($this->formIds, static fn (int $id): bool => $id > $bind[0]));
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $bind = []): string|false {
            $this->selects[] = [$sql, $bind];
            return in_array((int)$bind[0], $this->formIds, true) ? (string)$bind[0] : false;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->tester = new CommandTester(new RebuildStatsCommand($this->rebuilder, $resource));
    }

    public function testItRebuildsOnlyTheFormGivenByTheFormOption(): void
    {
        $rebuilder = $this->createMock(Rebuilder::class);
        $this->fixture(rebuilder: $rebuilder);
        $rebuilder->expects($this->once())->method('rebuild')->with(4);
        $this->formIds = [4];

        $this->assertSame(0, $this->tester->execute(['--form' => '4']));
        $this->assertStringContainsString('Rebuilt form 4', $this->tester->getDisplay());
    }

    public function testItSaysTheFormDoesNotExistInsteadOfRebuildingIt(): void
    {
        $rebuilder = $this->createMock(Rebuilder::class);
        $this->fixture(rebuilder: $rebuilder);
        $rebuilder->expects($this->never())->method('rebuild');

        $this->assertSame(1, $this->tester->execute(['--form' => '999999']));
        $this->assertStringContainsString('Form 999999 does not exist.', $this->tester->getDisplay());
        $this->assertStringContainsString('WHERE form_id = ?', $this->selects[0][0]);
    }

    public function testItRebuildsEveryFormInKeysetPagesWhenNoFormIsGiven(): void
    {
        $this->formIds = [2, 5, 9];
        $seen = [];
        $this->rebuilder->method('rebuild')->willReturnCallback(function (int $id) use (&$seen): void {
            $seen[] = $id;
        });

        $this->assertSame(0, $this->tester->execute([]));
        $this->assertSame([2, 5, 9], $seen);
        $this->assertStringNotContainsString('OFFSET', $this->selects[0][0]);
        $this->assertSame([0], $this->selects[0][1]);
    }

    public function testItReportsAFailedFormAndKeepsGoingWithTheOthers(): void
    {
        $this->formIds = [1, 2];
        $this->rebuilder->method('rebuild')->willReturnCallback(function (int $id): void {
            if ($id === 1) {
                throw new \RuntimeException('A rebuild of form 1 is already running.');
            }
        });

        $this->assertSame(1, $this->tester->execute([]));
        $this->assertStringContainsString('already running', $this->tester->getDisplay());
        $this->assertStringContainsString('Rebuilt form 2', $this->tester->getDisplay());
    }

    public function testItRejectsAnInvalidFormOption(): void
    {
        $rebuilder = $this->createMock(Rebuilder::class);
        $this->fixture(rebuilder: $rebuilder);
        $rebuilder->expects($this->never())->method('rebuild');

        $this->assertSame(1, $this->tester->execute(['--form' => 'abc']));
    }

    public function testItAcceptsAResetSpecsOptionOnTheRebuildCommand(): void
    {
        $rebuilder = $this->createMock(Rebuilder::class);
        $this->fixture(rebuilder: $rebuilder);
        $rebuilder->expects($this->once())->method('rebuild')->with(4, true);
        $this->formIds = [4];

        $this->assertSame(0, $this->tester->execute(['--form' => '4', '--reset-specs' => true]));
    }
}
