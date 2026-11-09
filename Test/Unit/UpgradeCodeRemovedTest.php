<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

class UpgradeCodeRemovedTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testItRemovesTheBackfillCommandAndBackfillerFromCodeAndDi(): void
    {
        $this->assertFileDoesNotExist(self::ROOT . '/Console/Command/BackfillSubmissionsCommand.php');
        $this->assertFileDoesNotExist(self::ROOT . '/Model/Submission/Backfiller.php');
        $this->assertFileDoesNotExist(self::ROOT . '/Test/Unit/Console/Command/BackfillSubmissionsCommandTest.php');
        $this->assertFileDoesNotExist(self::ROOT . '/Test/Unit/Model/Submission/BackfillerTest.php');
        $this->assertStringNotContainsStringIgnoringCase('backfill', (string)file_get_contents(self::ROOT . '/etc/di.xml'));
    }

    public function testItRemovesTheUpgradeOnlyDataPatchesAndTheirTests(): void
    {
        $this->assertSame([], glob(self::ROOT . '/Setup/Patch/Data/*') ?: []);
        $this->assertSame([], glob(self::ROOT . '/Test/Unit/Setup/Patch/Data/*') ?: []);
    }

    public function testItRemovesBackfillAndUpgradeWordingFromTheReadmePrivacyPageAndEveryTranslationFile(): void
    {
        $files = array_merge(
            [self::ROOT . '/README.md', self::ROOT . '/view/adminhtml/templates/privacy/index.phtml'],
            glob(self::ROOT . '/docs/*.md') ?: [],
            glob(self::ROOT . '/i18n/*.csv') ?: []
        );
        $this->assertGreaterThan(10, count($files));
        foreach ($files as $file) {
            $text = (string)file_get_contents($file);
            $this->assertStringNotContainsStringIgnoringCase('backfill', $text, $file);
            $this->assertStringNotContainsStringIgnoringCase('## Upgrading', $text, $file);
        }
    }
}
