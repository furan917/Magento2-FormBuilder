<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Form;

use Furan\FormBuilder\Model\Form\Identifier;
use PHPUnit\Framework\TestCase;

class IdentifierTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function fixture(): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/identifiers.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testItAcceptsAndRejectsTheSameIdentifiersInPhpAndTheBuilder(): void
    {
        $fixture = $this->fixture();
        foreach ($fixture['validIdentifiers'] as $identifier) {
            $this->assertTrue(Identifier::isValid($identifier), $identifier);
        }
        foreach ($fixture['invalidIdentifiers'] as $identifier) {
            $this->assertFalse(Identifier::isValid($identifier), $identifier);
        }
    }

    public function testItAcceptsAndRejectsTheSameTitlesInPhpAndTheBuilder(): void
    {
        $fixture = $this->fixture();
        foreach ($fixture['validTitles'] as $title) {
            $this->assertTrue(Identifier::isValidTitle(trim($title)), $title);
        }
        foreach ($fixture['invalidTitles'] as $title) {
            $this->assertFalse(Identifier::isValidTitle(trim($title)), $title);
        }
        $this->assertFalse(Identifier::isValidTitle(str_repeat('x', Identifier::MAX_TITLE_LENGTH + 1)));
        $this->assertTrue(Identifier::isValidTitle(str_repeat('x', Identifier::MAX_TITLE_LENGTH)));
    }

    public function testItKeepsTheIdentifierPatternInOnePlace(): void
    {
        $root = dirname(__DIR__, 4);
        $offenders = [];
        foreach (['Controller', 'Model'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && str_contains((string)file_get_contents($file->getPathname()), '[a-z0-9][a-z0-9-]{0,63}')) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $offenders, 'only Identifier::PATTERN_BODY, derived from MAX_LENGTH, carries the pattern');
        $this->assertSame('[a-z0-9][a-z0-9-]{0,63}', Identifier::PATTERN_BODY);
    }
}
