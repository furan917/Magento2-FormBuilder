<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldGroup;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Furan\FormBuilder\Api\Data\FormState;
use Furan\FormBuilder\Model\FieldGroup\GroupNormalizer;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Test\Unit\Helper\FieldGroupNormalizers;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class GroupNormalizerTest extends TestCase
{
    private function normalizer(array $extraActions = []): GroupNormalizer
    {
        return FieldGroupNormalizers::create($this->createStub(StoreManagerInterface::class), $extraActions);
    }

    private function field(string $id, string $name, array $extra = []): array
    {
        return $extra + ['id' => $id, 'kind' => 'field', 'type' => 'text', 'name' => $name, 'label' => ucfirst($name)];
    }

    public function testItStoresNoFieldGroupMarkerInAGroup(): void
    {
        $result = $this->normalizer()->normalize([
            $this->field('f_aaaa1', 'first', ['source_group' => 4]),
            ['id' => 's_aaaa1', 'kind' => 'section', 'title' => 'S', 'source_group' => 4, 'items' => [$this->field('f_aaaa2', 'second', ['source_group' => 4])]],
        ], [], []);

        $this->assertArrayNotHasKey('source_group', $result['fields'][0]);
        $this->assertArrayNotHasKey('source_group', $result['fields'][1]);
        $this->assertArrayNotHasKey('source_group', $result['fields'][1]['items'][0]);
    }

    public function testItNeverStoresAPageConditionOrSkipInAGroup(): void
    {
        $result = $this->normalizer()->normalize(
            [$this->field('f_aaaa1', 'first'), $this->field('f_aaaa2', 'second')],
            [],
            [0]
        );

        $this->assertSame(['f_aaaa1', 'f_aaaa2'], array_column($result['fields'], 'id'));
        $this->assertStringNotContainsString('"skip"', (string)json_encode($result['fields']));

        $page = ['id' => 'p_aaaa1', 'condition' => ['match' => 'all', 'rules' => []], 'skip' => null,
            'items' => [$this->field('f_aaaa1', 'first')]];
        $this->expectException(InvalidSchemaException::class);
        $this->normalizer()->normalize($page, [], [0]);
    }

    public function testItRefusesAnActionThatOnlyWorksOnPages(): void
    {
        $pageOnly = new class implements ConditionActionInterface {
            public function getCode(): string
            {
                return 'page_only';
            }

            public function getLabel(): string
            {
                return 'Page only';
            }

            public function targets(): array
            {
                return ['page'];
            }

            public function needsParam(): ?string
            {
                return null;
            }

            public function apply(FormState $state, string $targetId, mixed $param, bool $matched): void
            {
            }
        };
        $fields = [
            $this->field('f_aaaa1', 'first'),
            $this->field('f_aaaa2', 'second', ['condition' => [
                'actions' => [['action' => 'page_only']], 'match' => 'all',
                'rules' => [['field' => 'first', 'operator' => 'equals', 'value' => 'x']],
            ]]),
        ];

        $this->expectException(InvalidSchemaException::class);
        $this->normalizer(['page_only' => $pageOnly])->normalize($fields, [], [0]);
    }

    public function testItDropsTranslationsForPathsOutsideTheGroupsItems(): void
    {
        $translation = static fn (string $text): array => ['t' => $text, 's' => 'Email'];
        $result = $this->normalizer()->normalize(
            [$this->field('f_aaaa1', 'email', ['label' => 'Email'])],
            [
                0 => ['items' => ['f_aaaa1' => ['label' => $translation('Courriel')], 'f_other' => ['label' => $translation('X')]]],
                5 => ['items' => ['f_aaaa1' => ['label' => $translation('Nope')]]],
                1 => ['form' => ['submit_label' => $translation('Envoyer')], 'items' => ['f_aaaa1' => ['label' => $translation('Courriel')]]],
            ],
            [0, 1]
        );

        $this->assertSame([
            0 => ['items' => ['f_aaaa1' => ['label' => ['t' => 'Courriel', 's' => 'Email']]]],
            1 => ['items' => ['f_aaaa1' => ['label' => ['t' => 'Courriel', 's' => 'Email']]]],
        ], $result['translations']);
    }
}
