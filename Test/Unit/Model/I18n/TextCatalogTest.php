<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\I18n;

use Furan\FormBuilder\Model\FieldType\Consent;
use Furan\FormBuilder\Model\FieldType\DateRange;
use Furan\FormBuilder\Model\FieldType\Matrix;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\I18n\TextCatalog;
use PHPUnit\Framework\TestCase;

class TextCatalogTest extends TestCase
{
    public function testItListsTheSameTranslatableTextsAsTheSharedFixture(): void
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/translatable-texts.json'), true);
        $catalog = new TextCatalog(new Pool([
            'text' => new Text(), 'select' => new Select(), 'matrix' => new Matrix(), 'consent' => new Consent(),
            'date_range' => new DateRange(),
        ]));
        $actual = array_map(
            static fn(array $e): array => [$e['path'], $e['text'], $e['max'], $e['multiline'], $e['url']],
            $catalog->collect($fixture['schema'], $fixture['settings'])
        );
        $this->assertSame($fixture['expected'], $actual);
    }
}
