<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminStylesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../view/adminhtml';

    /** @return array<string, array{string, list<string>}> */
    public static function pages(): array
    {
        return [
            'analytics' => ['furanform_analytics_index', ['analytics']],
            'field group edit' => ['furanform_fieldgroup_edit', ['builder']],
            'field group list' => ['furanform_fieldgroup_index', ['rules']],
            'form edit' => ['furanform_form_edit', ['builder']],
            'privacy' => ['furanform_privacy_index', ['privacy']],
            'rule edit' => ['furanform_rule_edit', ['rules']],
            'rule list' => ['furanform_rule_index', ['rules']],
            'submission' => ['furanform_submission_view', ['submission']],
            'delivery log' => ['furanform_webhookdelivery_index', ['rules', 'webhooks']],
            'delivery' => ['furanform_webhookdelivery_view', ['rules', 'webhooks']],
            'webhook edit' => ['furanform_webhook_edit', ['rules', 'webhooks']],
            'webhook list' => ['furanform_webhook_index', ['rules', 'webhooks']],
        ];
    }

    /** @param list<string> $styles */
    #[DataProvider('pages')]
    public function testItDeclaresEachAdminPagesStylesheetsInTheLayoutHeadInOrder(string $handle, array $styles): void
    {
        $xml = simplexml_load_file(self::ROOT . '/layout/' . $handle . '.xml');
        $this->assertNotFalse($xml);
        $declared = array_map('strval', $xml->xpath('/page/head/css/@src') ?: []);

        $this->assertSame(array_map(static fn (string $s): string => 'Furan_FormBuilder::css/' . $s . '.css', $styles), $declared);
        foreach ($styles as $style) {
            $this->assertFileExists(self::ROOT . '/web/css/' . $style . '.css');
        }
    }

    public function testItLoadsNoStylesheetFromTheBodyOfAnAdminTemplate(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/templates'));
        $offenders = [];
        foreach ($files as $file) {
            if ($file->isFile() && str_contains((string)file_get_contents($file->getPathname()), 'rel="stylesheet"')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testThePagesThatReuseAnotherHandleInheritItsStylesheets(): void
    {
        foreach (['furanform_form_new' => 'furanform_form_edit', 'furanform_privacy_preview' => 'furanform_privacy_index'] as $page => $handle) {
            $xml = (string)file_get_contents(self::ROOT . '/layout/' . $page . '.xml');
            $this->assertStringContainsString('<update handle="' . $handle . '"/>', $xml);
        }
    }
}
