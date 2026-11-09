<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\Schema\ContentTypes;
use PHPUnit\Framework\TestCase;

class ContentTypesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../';

    public function testItKeepsTheContentTypeListInOnePlace(): void
    {
        $this->assertSame(
            ['heading', 'paragraph', 'divider', 'spacer', 'rich_text', 'notice', 'summary'],
            array_keys(ContentTypes::labels())
        );
        $this->assertTrue(ContentTypes::has('notice'));
        $this->assertFalse(ContentTypes::has('product_card'));
        $this->assertSame('Rich text', ContentTypes::label('rich_text'));
        $this->assertNull(ContentTypes::label('product_card'));

        foreach (['Model/Schema/Normalizer.php', 'Block/Form.php', 'Model/Admin/BuilderSharedConfig.php'] as $file) {
            $source = (string)file_get_contents(self::ROOT . $file);
            $this->assertStringContainsString('ContentTypes::', $source, $file);
            $this->assertStringNotContainsString('CONTENT_TYPES', $source, $file);
        }
    }
}
