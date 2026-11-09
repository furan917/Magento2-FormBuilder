<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Console\Command;

use Furan\FormBuilder\Console\Command\RehashSubmissionsCommand;
use Furan\FormBuilder\Model\Submission\Rehasher;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RehashSubmissionsCommandTest extends TestCase
{
    private Rehasher&Stub $rehasher;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->rehasher = $this->createMock(Rehasher::class);
        $this->tester = new CommandTester(new RehashSubmissionsCommand($this->rehasher));
    }

    public function testItRunsWithTheDefaultBatchSizeAndReportsTheCounts(): void
    {
        $this->rehasher->expects($this->once())->method('run')->with(500, null)->willReturn(
            ['scanned' => 10, 'rekeyed' => 6, 'unresolved' => 2, 'respondent_keys' => 4]
        );

        $this->assertSame(0, $this->tester->execute([]));
        $out = $this->tester->getDisplay();
        $this->assertStringContainsString('Scanned: 10', $out);
        $this->assertStringContainsString('Re-keyed: 6', $out);
        $this->assertStringContainsString('Unresolved: 2', $out);
        $this->assertStringContainsString('Respondent keys rewritten: 4', $out);
    }

    public function testItPassesTheOptionsAndRejectsABatchSizeAboveTheMaximum(): void
    {
        $this->rehasher->expects($this->once())->method('run')->with(100, 3)->willReturn(
            ['scanned' => 0, 'rekeyed' => 0, 'unresolved' => 0, 'respondent_keys' => 0]
        );

        $this->tester->execute(['--batch-size' => '100', '--form-id' => '3']);
        $this->assertSame(1, $this->tester->execute(['--batch-size' => '5001']));
    }

    /** @return array<string, array{string}> */
    public static function badFormIds(): array
    {
        return ['letters' => ['abc'], 'zero' => ['0'], 'negative' => ['-3'], 'mixed' => ['3x']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFormIds')]
    public function testItRefusesAFormIdThatIsNotAPositiveNumber(string $formId): void
    {
        $this->rehasher->expects($this->never())->method('run');

        $this->assertSame(1, $this->tester->execute(['--form-id' => $formId]));
        $this->assertStringContainsString('--form-id must be a positive form ID.', $this->tester->getDisplay());
    }
}
