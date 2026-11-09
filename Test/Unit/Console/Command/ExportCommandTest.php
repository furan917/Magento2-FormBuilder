<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Console\Command;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Console\Command\ExportCommand;
use Furan\FormBuilder\Model\Definition\Exporter;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ExportCommandTest extends TestCase
{
    private FormRepositoryInterface&Stub $repository;
    private FormResource&Stub $resource;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FormRepositoryInterface::class);
        $this->resource = $this->createStub(FormResource::class);
        $exporter = $this->createStub(Exporter::class);
        $exporter->method('export')->willReturn(['identifier' => 'contact', 'title' => 'Café/Contact']);
        $this->repository->method('getById')->willReturnCallback(
            fn (int $id) => $id === 7
                ? $this->createStub(FormInterface::class)
                : throw new NoSuchEntityException(__('x'))
        );
        $this->tester = new CommandTester(new ExportCommand($this->repository, $exporter, $this->resource, new \Magento\Framework\Filesystem\Driver\File()));
    }

    public function testItExportsAFormDefinitionByIdentifierOrId(): void
    {
        $this->resource->method('getIdByIdentifier')->willReturnMap([['contact', 7], ['7', null]]);

        $this->assertSame(0, $this->tester->execute(['form' => 'contact']));
        $this->assertStringContainsString('"title": "Café/Contact"', $this->tester->getDisplay());
        $this->assertSame(0, $this->tester->execute(['form' => '7']));
        $this->assertSame(1, $this->tester->execute(['form' => 'nope']));
        $this->assertSame(1, $this->tester->execute(['form' => '99']));
    }

    public function testItWritesTheExportToAFileWhenRequested(): void
    {
        $this->resource->method('getIdByIdentifier')->willReturn(7);
        $file = tempnam(sys_get_temp_dir(), 'ffb');
        try {
            $this->assertSame(0, $this->tester->execute(['form' => 'contact', '--output' => $file]));
            $this->assertSame('contact', json_decode((string)file_get_contents($file), true)['identifier']);
            $this->assertStringNotContainsString('{', $this->tester->getDisplay());
        } finally {
            unlink($file);
        }
    }

    public function testItFailsInsteadOfWritingAnEmptyFileWhenTheDefinitionCannotBeEncoded(): void
    {
        $this->resource->method('getIdByIdentifier')->willReturn(7);
        $exporter = $this->createStub(Exporter::class);
        $exporter->method('export')->willReturn(['title' => "bad \xB1 utf8"]);
        $tester = new CommandTester(new ExportCommand($this->repository, $exporter, $this->resource, new \Magento\Framework\Filesystem\Driver\File()));
        $file = tempnam(sys_get_temp_dir(), 'ffb');
        try {
            $this->assertSame(1, $tester->execute(['form' => 'contact', '--output' => $file]));
            $this->assertSame('', (string)file_get_contents($file));
            $this->assertSame(1, $tester->execute(['form' => 'contact']));
        } finally {
            unlink($file);
        }
    }

    /** @return array<string, array{string}> */
    public static function unwritablePaths(): array
    {
        return [
            'read-only file' => ['/etc/passwd'],
            'missing directory' => [sys_get_temp_dir() . '/ffb-missing-dir/out.json'],
            'directory' => [sys_get_temp_dir()],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unwritablePaths')]
    public function testItRefusesAnUnwritableOutputPathWithACleanError(string $path): void
    {
        $this->resource->method('getIdByIdentifier')->willReturn(7);
        $warnings = [];
        // Like Magento's error handler, only warnings that were not suppressed with @ count.
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            if (error_reporting() & $level) {
                $warnings[] = $message;
            }
            return true;
        });
        try {
            $code = $this->tester->execute(['form' => 'contact', '--output' => $path]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $code);
        $this->assertSame([], $warnings);
        $this->assertStringContainsString(sprintf('Cannot write to "%s".', $path), $this->tester->getDisplay());
    }
}
