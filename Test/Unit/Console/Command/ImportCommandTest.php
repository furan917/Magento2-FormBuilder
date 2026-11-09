<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Console\Command;

use Furan\FormBuilder\Console\Command\ImportCommand;
use Furan\FormBuilder\Model\Definition\Importer;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCommandTest extends TestCase
{
    private Importer&Stub $importer;
    private CommandTester $tester;
    private string $file;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?Importer $importer = null): void
    {
        $this->importer = $importer ?? $this->createStub(Importer::class);
        $this->tester = new CommandTester(
            new ImportCommand($this->importer)
        );
        $this->file = tempnam(sys_get_temp_dir(), 'ffb');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function form(): Form
    {
        $form = $this->createStub(Form::class);
        $form->method('getIdentifier')->willReturn('contact');
        $form->method('getId')->willReturn(12);
        return $form;
    }

    public function testItImportsANewFormThroughTheNormalizers(): void
    {
        $importer = $this->createMock(Importer::class);
        $this->fixture(importer: $importer);
        file_put_contents($this->file, '{"identifier":"contact"}');
        $importer->expects($this->once())->method('import')
            ->with(['identifier' => 'contact'], false)->willReturn($this->form());

        $this->assertSame(0, $this->tester->execute(['file' => $this->file]));
        $this->assertStringContainsString('Imported form "contact" (ID 12).', $this->tester->getDisplay());
    }

    public function testItRefusesToOverwriteAnExistingIdentifierWithoutUpdate(): void
    {
        $importer = $this->createMock(Importer::class);
        $this->fixture(importer: $importer);
        file_put_contents($this->file, '{"identifier":"contact"}');
        $importer->method('import')->with($this->anything(), false)
            ->willThrowException(new InvalidSchemaException(['already exists']));

        $this->assertSame(1, $this->tester->execute(['file' => $this->file]));
        $this->assertStringContainsString('already exists', $this->tester->getDisplay());
    }

    public function testItUpdatesAnExistingFormWithTheUpdateOption(): void
    {
        $importer = $this->createMock(Importer::class);
        $this->fixture(importer: $importer);
        file_put_contents($this->file, '{"identifier":"contact"}');
        $importer->expects($this->once())->method('import')
            ->with($this->anything(), true)->willReturn($this->form());

        $this->assertSame(0, $this->tester->execute(['file' => $this->file, '--update' => true]));
    }

    public function testItReportsNormalizerErrorsAndExitsWithFailure(): void
    {
        file_put_contents($this->file, '{"identifier":"contact"}');
        $this->importer->method('import')->willThrowException(new InvalidSchemaException(['err one', 'err two']));

        $this->assertSame(1, $this->tester->execute(['file' => $this->file]));
        $this->assertStringContainsString('err one', $this->tester->getDisplay());
        $this->assertStringContainsString('err two', $this->tester->getDisplay());
    }

    public function testItRejectsUnreadableOversizedOrInvalidJsonFiles(): void
    {
        $importer = $this->createMock(Importer::class);
        $this->fixture(importer: $importer);
        $importer->expects($this->never())->method('import');

        $this->assertSame(1, $this->tester->execute(['file' => $this->file . '.missing']));
        file_put_contents($this->file, '{not json');
        $this->assertSame(1, $this->tester->execute(['file' => $this->file]));
        file_put_contents($this->file, str_repeat('a', 2097153));
        $this->assertSame(1, $this->tester->execute(['file' => $this->file]));
        $this->assertStringContainsString('2 MB', $this->tester->getDisplay());
    }
}
