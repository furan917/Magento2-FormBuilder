<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Schema fields are walked through Model/Schema/SchemaFields only. Templates are not scanned.
 */
class SchemaWalkerGuardTest extends TestCase
{
    /** File => reason it may check for sections or read nested items itself. */
    private const ALLOWED = [
        'Block/Form.php' => 'renders every kind of item and reads conditions on pages, sections and content',
        'Model/Storefront/Eagerness.php' => 'reads the conditions and skips on pages, sections and fields alike',
        'Model/Submission/AnswerView.php' => 'groups answers by the page and section layout',
        'Model/Condition/Evaluator.php' => 'evaluates the conditions of pages, sections, fields and content',
        'Model/I18n/TextCatalog.php' => 'collects the texts of pages, sections, fields and content',
    ];

    public function testItReplacesEveryHandRolledWalker(): void
    {
        $root = dirname(__DIR__, 2);
        // A comparison with the section kind, or a read of nested `items` with a default (not a grid `['data']['items']`).
        $walker = '/[!=]==\s*\'section\'|\'section\'\s*[!=]==|(?<!\[\'data\'\])\[\'items\'\]\s*\?\?/';

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!preg_match('/\.php$/', $relative)
                || preg_match('#^(Test|vendor|Model/Schema)/#', $relative)
                || isset(self::ALLOWED[$relative])
            ) {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match($walker, $line)) {
                    $offenders[] = $relative . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testItKeepsEveryAllowedFileInUse(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::ALLOWED as $file => $reason) {
            $this->assertFileExists($root . '/' . $file, $reason);
            $this->assertMatchesRegularExpression(
                '/\'section\'|\[\'items\'\]/',
                (string)file_get_contents($root . '/' . $file),
                $reason
            );
        }
    }
}
