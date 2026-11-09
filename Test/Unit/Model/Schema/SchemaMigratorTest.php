<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Api\SchemaMigrationInterface;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Schema\UnsupportedSchemaVersionException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaMigratorTest extends TestCase
{
    private function migration(int $from, string $mark): SchemaMigrationInterface
    {
        $migration = $this->createStub(SchemaMigrationInterface::class);
        $migration->method('fromVersion')->willReturn($from);
        $migration->method('migrate')->willReturnCallback(
            static function (array $schema) use ($mark): array {
                $schema['trail'][] = $mark;
                return $schema;
            }
        );
        return $migration;
    }

    private function migrator(int $current, array $migrations = []): SchemaMigrator
    {
        return new SchemaMigrator($this->createStub(LoggerInterface::class), $migrations, $current);
    }

    public function testItRunsMigrationsInOrderFromTheStoredVersionToTheCurrentVersion(): void
    {
        $migrator = $this->migrator(4, [
            '3' => $this->migration(3, 'c'),
            '1' => $this->migration(1, 'a'),
            '2' => $this->migration(2, 'b'),
        ]);

        $result = $migrator->upgrade(['version' => 2, 'pages' => [['id' => 'p_aaaa']]]);

        $this->assertSame(['b', 'c'], $result['trail']);
        $this->assertSame(4, $result['version']);
        $this->assertSame([['id' => 'p_aaaa']], $result['pages']);
        $this->assertSame(['a', 'b', 'c'], $migrator->upgrade(['pages' => []])['trail']);
    }

    public function testItLeavesASchemaAtTheCurrentVersionUnchanged(): void
    {
        $schema = ['version' => 3, 'pages' => []];
        $migrator = $this->migrator(3, ['2' => $this->migration(2, 'b')]);

        $this->assertSame($schema, $migrator->upgrade($schema));
        $this->assertTrue($migrator->isSupported($schema));
    }

    public function testItRefusesASchemaWithAVersionNewerThanTheModuleSupports(): void
    {
        $migrator = $this->migrator(2);
        $schema = ['version' => 3, 'pages' => []];

        $this->assertFalse($migrator->isSupported($schema));
        try {
            $migrator->upgrade($schema);
            $this->fail('Expected an exception');
        } catch (UnsupportedSchemaVersionException $e) {
            $this->assertSame(3, $e->getVersion());
            $this->assertStringContainsString('newer', $e->getMessage());
        }
    }

    public function testItRefusesASchemaWhenAMigrationStepIsMissing(): void
    {
        $this->expectException(UnsupportedSchemaVersionException::class);
        $this->migrator(3, ['2' => $this->migration(2, 'b')])->upgrade(['version' => 1]);
    }

    public function testItSkipsAMigrationRegisteredUnderTheWrongVersion(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $migrator = new SchemaMigrator($logger, ['5' => $this->migration(1, 'a')], 2);

        $this->expectException(UnsupportedSchemaVersionException::class);
        $migrator->upgrade(['version' => 1]);
    }

    public function testItUpgradesStoredJsonAndLeavesCurrentOrUnreadableJsonAlone(): void
    {
        $migrator = $this->migrator(2, ['1' => $this->migration(1, 'a')]);

        $upgraded = json_decode($migrator->upgradeJson('{"version":1,"pages":[]}'), true);
        $this->assertSame(['a'], $upgraded['trail']);
        $this->assertSame(2, $upgraded['version']);
        $this->assertSame('{"version":2,"pages":[]}', $migrator->upgradeJson('{"version":2,"pages":[]}'));
        $this->assertSame('{"version":9}', $migrator->upgradeJson('{"version":9}'));
        $this->assertSame('not json', $migrator->upgradeJson('not json'));
    }
}
