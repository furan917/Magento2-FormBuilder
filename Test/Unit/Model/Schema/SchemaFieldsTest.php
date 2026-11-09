<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\Schema\SchemaFields;
use PHPUnit\Framework\TestCase;

class SchemaFieldsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return ['version' => 3, 'pages' => [
            ['id' => 'p1', 'title' => 'One', 'condition' => ['rules' => []], 'items' => [
                ['id' => 'f1', 'kind' => 'field', 'name' => 'a', 'type' => 'text'],
                ['id' => 's1', 'kind' => 'section', 'title' => 'Sec', 'condition' => null, 'skip' => ['x'], 'items' => [
                    ['id' => 'f2', 'kind' => 'field', 'name' => 'b', 'type' => 'email'],
                    ['id' => 'c1', 'kind' => 'content', 'type' => 'heading', 'title' => 'H'],
                ]],
                ['id' => 'f3', 'kind' => 'field', 'name' => 'c', 'type' => 'text'],
            ]],
            ['id' => 'p2', 'title' => 'Two', 'items' => [
                ['id' => 'f4', 'kind' => 'field', 'name' => 'd', 'type' => 'text'],
            ]],
        ]];
    }

    public function testItWalksFieldsInDocumentOrderThroughPagesAndSections(): void
    {
        $names = array_column(SchemaFields::all($this->schema()), 'name');

        $this->assertSame(['a', 'b', 'c', 'd'], $names);
        $this->assertSame(['a', 'b', 'c', 'd'], array_keys(SchemaFields::byName($this->schema())));
        $this->assertSame('email', SchemaFields::byName($this->schema())['b']['type']);
    }

    public function testItRewritesAndFiltersFieldsWithoutTouchingPageOrSectionMetadata(): void
    {
        $schema = $this->schema();

        $mapped = SchemaFields::map($schema, static fn (array $f): array => ['label' => 'L'] + $f);
        $this->assertSame('L', $mapped['pages'][0]['items'][1]['items'][0]['label']);
        $this->assertSame(3, $mapped['version']);
        unset($mapped['pages'][0]['items'][0]['label'], $mapped['pages'][0]['items'][1]['items'][0]['label'],
            $mapped['pages'][0]['items'][2]['label'], $mapped['pages'][1]['items'][0]['label']);
        $this->assertSame($schema, $mapped);

        $filtered = SchemaFields::filter($schema, static fn (array $f): bool => $f['type'] !== 'text');
        $this->assertSame(['b'], array_column(SchemaFields::all($filtered), 'name'));
        $this->assertSame(['rules' => []], $filtered['pages'][0]['condition']);
        $this->assertSame(['x'], $filtered['pages'][0]['items'][0]['skip']);
        $this->assertSame('c1', $filtered['pages'][0]['items'][0]['items'][1]['id']);
        $this->assertSame([], $filtered['pages'][1]['items']);
    }

    public function testItMapsOtherKindsWhenAsked(): void
    {
        $mapped = SchemaFields::map(
            $this->schema(),
            static fn (array $item): array => ['seen' => true] + $item,
            ['page', 'section', 'content']
        );

        $this->assertTrue($mapped['pages'][0]['seen']);
        $this->assertTrue($mapped['pages'][0]['items'][1]['seen']);
        $this->assertTrue($mapped['pages'][0]['items'][1]['items'][1]['seen']);
        $this->assertArrayNotHasKey('seen', $mapped['pages'][0]['items'][0]);
    }

    public function testItSkipsNonArrayItemsAndItemsWithoutAKind(): void
    {
        $schema = ['pages' => [
            'junk',
            ['items' => [
                'junk',
                ['name' => 'nokind'],
                ['kind' => 'field', 'name' => 'ok'],
                ['kind' => 'section', 'items' => ['junk', ['kind' => 'field', 'name' => 'inner'], ['name' => 'x']]],
                ['kind' => 'section'],
            ]],
            ['title' => 'no items'],
        ]];

        $this->assertSame(['ok', 'inner'], array_column(SchemaFields::all($schema), 'name'));
        $this->assertSame([], SchemaFields::all([]));
        $this->assertSame([], SchemaFields::all(['pages' => 'x']));
        $this->assertSame(['name' => 'nokind'], SchemaFields::filter($schema, static fn (): bool => false)['pages'][1]['items'][1]);
    }
}
