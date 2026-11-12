<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Checkbox;
use Furan\FormBuilder\Model\FieldType\Email;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Multiselect;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Schema\PublicSchema;
use Furan\FormBuilder\Model\Validation\Rule\MinLength;
use Furan\FormBuilder\Model\Validation\RulePool;
use PHPUnit\Framework\TestCase;

class PublicSchemaTest extends TestCase
{
    private function build(array $items, array $allowed = ['pdf', 'jpg', 'png'], array $extraTypes = []): array
    {
        $config = $this->createStub(Config::class);
        $config->method('getAllowedExtensions')->willReturn($allowed);
        $publicSchema = new PublicSchema(
            new RulePool(['min_length' => new MinLength()]),
            new Pool($extraTypes + ['text' => new Text(), 'email' => new Email(), 'file' => new File(),
                'checkbox' => new Checkbox(), 'multiselect' => new Multiselect(), 'matrix' => new \Furan\FormBuilder\Model\FieldType\Matrix(),
                'date_range' => new \Furan\FormBuilder\Model\FieldType\DateRange(),
                'map' => new \Furan\FormBuilder\Model\FieldType\Map($this->createStub(\Furan\FormBuilder\Model\Config::class), $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class), \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create(), \Furan\FormBuilder\Test\Unit\Helper\MapMarkerFactory::create()),
                'address' => new \Furan\FormBuilder\Model\FieldType\Address(
                    $this->createStub(\Furan\FormBuilder\Model\Directory\AddressRules::class),
                    \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create()
                )]),
            $config,
            new \Furan\FormBuilder\Model\Upload\ExtensionList()
        );
        $schema = ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'title' => 'T', 'condition' => null, 'items' => $items]]];
        return $publicSchema->build($schema)['pages'][0]['items'];
    }

    public function testItLeavesTheFieldGroupMarkerOutOfThePublicSchema(): void
    {
        $items = $this->build([
            ['id' => 's_aaaa', 'kind' => 'section', 'title' => 'S', 'source_group' => 3, 'items' => [
                ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a', 'label' => 'A', 'rules' => [], 'source_group' => 3],
            ]],
            ['id' => 'c_aaaa', 'kind' => 'content', 'type' => 'heading', 'text' => 'H', 'source_group' => 3],
        ]);

        $this->assertArrayNotHasKey('source_group', $items[0]);
        $this->assertArrayNotHasKey('source_group', $items[0]['items'][0]);
        $this->assertArrayNotHasKey('source_group', $items[1]);
    }

    public function testItSkipsAStoredNewsletterFieldWhenUnavailable(): void
    {
        $bridge = $this->createStub(\Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn(false);
        $news = ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'newsletter', 'name' => 'news', 'label' => 'News', 'rules' => []];
        $text = ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'name', 'label' => 'Name', 'rules' => []];

        $items = $this->build([$text, $news], extraTypes: ['newsletter' => new \Furan\FormBuilder\Model\FieldType\Newsletter($bridge)]);

        $this->assertSame(['name'], array_column($items, 'name'));
    }

    public function testItAddsClientMessagesFromAnyTypeImplementingClientMessagesInterface(): void
    {
        $badge = new class extends \Furan\FormBuilder\Model\FieldType\AbstractFieldType implements
            \Furan\FormBuilder\Api\FieldType\ClientMessagesInterface
        {
            public function getCode(): string
            {
                return 'badge';
            }

            public function getLabel(): string
            {
                return 'Badge';
            }

            public function getClientMessages(array $field): array
            {
                return ['messages' => ['bad' => 'Bad ' . $field['name']], 'rows_message' => 'Rows'];
            }
        };

        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'badge', 'name' => 'b', 'rules' => []],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 't', 'rules' => []],
        ], ['pdf'], ['badge' => $badge]);

        $this->assertSame(['bad' => 'Bad b'], $items[0]['messages']);
        $this->assertSame('Rows', $items[0]['rows_message']);
        $this->assertArrayNotHasKey('messages', $items[1]);
    }

    public function testItResolvesRuleAndRequiredMessagesInThePublicSchema(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a', 'rules' => [
                ['rule' => 'min_length', 'value' => 3, 'message' => ''],
                ['rule' => 'min_length', 'value' => 5, 'message' => 'Custom'],
            ]],
            ['id' => 'c_aaaa', 'kind' => 'content', 'type' => 'heading', 'text' => 'Hi'],
            ['id' => 's_aaaa', 'kind' => 'section', 'title' => 'S', 'items' => [
                ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'b', 'rules' => []],
            ]],
        ]);

        $this->assertSame('Please enter at least 3 characters.', $items[0]['rules'][0]['message']);
        $this->assertSame('Custom', $items[0]['rules'][1]['message']);
        $this->assertSame('This field is required.', $items[0]['required_message']);
        $this->assertFalse($items[0]['multi']);
        $this->assertFalse($items[0]['file']);
        $this->assertSame(['id' => 'c_aaaa', 'kind' => 'content', 'type' => 'heading', 'text' => 'Hi'], $items[1]);
        $this->assertSame('This field is required.', $items[2]['items'][0]['required_message']);
    }

    public function testItAddsTheAcceptAttributeForFileFields(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'file', 'name' => 'a', 'rules' => [],
             'settings' => ['extensions' => ['jpg', 'PDF', 'exe'], 'max_size_mb' => 2]],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'file', 'name' => 'b', 'rules' => [], 'settings' => []],
        ]);

        $this->assertTrue($items[0]['file']);
        $this->assertSame('.pdf,.jpg', $items[0]['settings']['accept']);
        $this->assertSame(2, $items[0]['settings']['max_size_mb']);
        $this->assertSame('.pdf,.jpg,.png', $items[1]['settings']['accept']);
    }

    public function testItKeepsFieldSettingsAndAddsTheTypeMessage(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'email', 'name' => 'a', 'rules' => [], 'settings' => []],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'checkbox', 'name' => 'b', 'rules' => [],
             'settings' => ['checked_value' => 'yes']],
            ['id' => 'f_cccc', 'kind' => 'field', 'type' => 'multiselect', 'name' => 'c', 'rules' => [], 'settings' => []],
            ['id' => 'f_dddd', 'kind' => 'field', 'type' => 'text', 'name' => 'd', 'rules' => [], 'settings' => []],
        ]);

        $this->assertSame((new Email())->getInvalidMessage([]), $items[0]['type_message']);
        $this->assertNotEmpty($items[0]['type_message']);
        $this->assertSame(['checked_value' => 'yes'], $items[1]['settings']);
        $this->assertSame((new Checkbox())->getInvalidMessage($items[1]), $items[1]['type_message']);
        $this->assertArrayNotHasKey('type_message', $items[3]);
        $this->assertTrue($items[2]['multi']);
    }

    public function testItAddsTheRowsMessageForMatrixFieldsOnly(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'matrix', 'name' => 'a', 'rules' => [],
             'options' => [['label' => 'Yes', 'value' => 'y', 'description' => 'Sure']], 'settings' => ['rows' => ['R']]],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'b', 'rules' => [], 'settings' => []],
        ]);

        $this->assertSame('Please answer every row.', $items[0]['rows_message']);
        $this->assertTrue($items[0]['multi']);
        $this->assertSame('Sure', $items[0]['options'][0]['description']);
        $this->assertArrayNotHasKey('rows_message', $items[1]);
    }

    public function testItEmitsTheServerTranslatedDateRangeMessages(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'date_range', 'name' => 'a', 'rules' => [],
             'settings' => ['min_days' => 3, 'min_date' => '2026-02-01']],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'b', 'rules' => []],
        ]);

        $this->assertSame('Please choose a range of at least 3 days.', $items[0]['messages']['min_days']);
        $this->assertSame('Please choose dates on or after 2026-02-01.', $items[0]['messages']['min_date']);
        $this->assertSame(
            ['order', 'incomplete', 'min_days', 'max_days', 'min_date', 'max_date'],
            array_keys($items[0]['messages'])
        );
        $this->assertArrayNotHasKey('messages', $items[1]);
    }

    public function testItEmitsTheServerTranslatedAddressMessages(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'address', 'name' => 'a', 'rules' => []],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'b', 'rules' => []],
        ]);

        $this->assertSame(
            ['country', 'street', 'city', 'region', 'postcode', 'lines', 'postcode_hint'],
            array_keys($items[0]['messages'])
        );
        $this->assertSame('Please enter a city.', $items[0]['messages']['city']);
        $this->assertArrayNotHasKey('messages', $items[1]);
    }

    public function testItEmitsTheServerTranslatedMapMessages(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'map', 'name' => 'm', 'rules' => []],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'b', 'rules' => []],
        ]);

        $this->assertSame(
            ['invalid' => 'Please choose a valid location.', 'incomplete' => 'Please enter both a latitude and a longitude.'],
            $items[0]['messages']
        );
        $this->assertArrayNotHasKey('messages', $items[1]);
    }

    public function testItOmitsUnknownTypeFieldsFromThePublicSchemaSoTheBrowserNeverRequiresThem(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'gone', 'name' => 'a', 'required' => true, 'rules' => []],
            ['id' => 's_aaaa', 'kind' => 'section', 'title' => 'S', 'items' => [
                ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'gone', 'name' => 'b', 'rules' => []],
                ['id' => 'f_cccc', 'kind' => 'field', 'type' => 'text', 'name' => 'c', 'rules' => []],
            ]],
            ['id' => 'f_dddd', 'kind' => 'field', 'type' => 'text', 'name' => 'd', 'rules' => []],
        ]);

        $this->assertSame(['s_aaaa', 'f_dddd'], array_column($items, 'id'));
        $this->assertSame(['f_cccc'], array_column($items[0]['items'], 'id'));
    }

    public function testItEmitsTheRelativeDateFormatOfFieldsThatSupportIt(): void
    {
        $field = static fn (string $id, string $type): array => [
            'id' => $id, 'kind' => 'field', 'type' => $type, 'name' => $type, 'label' => $type, 'rules' => [],
        ];
        $items = $this->build(
            [$field('f_aaaa', 'date'), $field('f_bbbb', 'datetime'), $field('f_cccc', 'month'), $field('f_dddd', 'text')],
            ['pdf'],
            [
                'date' => new \Furan\FormBuilder\Model\FieldType\Date(),
                'datetime' => new \Furan\FormBuilder\Model\FieldType\DateTime(),
                'month' => new \Furan\FormBuilder\Model\FieldType\Month(),
            ]
        );
        $this->assertSame('Y-m-d', $items[0]['relative_date']);
        $this->assertSame('Y-m-d', $items[1]['relative_date']);
        $this->assertSame('Y-m', $items[2]['relative_date']);
        $this->assertArrayNotHasKey('relative_date', $items[3]);
    }

    public function testItMarksFieldsTheServerOverwritesWithASetValueAction(): void
    {
        $base = ['kind' => 'field', 'label' => 'L', 'rules' => []];
        $items = $this->build([
            ['id' => 'f_aaaa', 'type' => 'text', 'name' => 'plain'] + $base,
            ['id' => 'f_bbbb', 'type' => 'text', 'name' => 'locked', 'locked' => true] + $base,
            ['id' => 'f_cccc', 'type' => 'hidden', 'name' => 'ref'] + $base,
        ], extraTypes: ['hidden' => new \Furan\FormBuilder\Model\FieldType\Hidden()]);

        $this->assertSame([false, true, true], array_map(static fn (array $f): bool => $f['overwritable'] ?? false, $items));
    }

    public function testItMarksServerValueFieldsSoTheBrowserNeverRequiresThem(): void
    {
        $base = ['kind' => 'field', 'label' => 'L', 'rules' => []];
        $items = $this->build([
            ['id' => 'f_aaaa', 'type' => 'text', 'name' => 'plain', 'locked' => true] + $base,
            ['id' => 'f_cccc', 'type' => 'hidden', 'name' => 'ref'] + $base,
        ], extraTypes: ['hidden' => new \Furan\FormBuilder\Model\FieldType\Hidden()]);

        $this->assertSame([false, true], array_map(static fn (array $f): bool => $f['server_value'] ?? false, $items));
    }

    public function testItNormalisesTheFieldsFileTypesBeforeNarrowingTheAcceptList(): void
    {
        $items = $this->build([
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'file', 'name' => 'a', 'rules' => [],
             'settings' => ['extensions' => [' .PNG', '', 'p df']]],
        ]);

        $this->assertSame('.png', $items[0]['settings']['accept']);
    }
}
