<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\FieldGroupFactory;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup as FieldGroupResource;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup\Collection;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup\CollectionFactory;
use Furan\FormBuilder\Test\Unit\Helper\FieldGroupNormalizers;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class FieldGroupManagerTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private FieldGroup $group;
    private FieldGroupResource $resource;
    /** @var array<int, array{string, mixed}> */
    private array $filters = [];
    private int $taken = 0;
    private int $storedId = 0;

    private function manager(): FieldGroupManager
    {
        $storedId = fn (): ?int => $this->storedId ?: null;
        $this->group = new class ($storedId) extends FieldGroup {
            public function __construct(private readonly \Closure $storedId)
            {
            }

            public function getId(): ?int
            {
                return ($this->storedId)();
            }
        };
        $factory = $this->createStub(FieldGroupFactory::class);
        $factory->method('create')->willReturn($this->group);
        $this->resource = $this->double('resource', FieldGroupResource::class);
        $this->resource->method('load')->willReturnCallback(function (FieldGroup $group, $id) {
            $this->storedId = (int)$id === 9 ? 9 : 0;
            if ($this->storedId) {
                $group->setData(['name' => 'Contact', 'description' => 'old', 'fields_json' => '[{"id":"f_keep1"}]', 'translations_json' => '{}']);
            }
            return $this->resource;
        });

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function (string $field, $condition) use ($collection) {
            $this->filters[] = [$field, $condition];
            return $collection;
        });
        $collection->method('getSize')->willReturnCallback(fn () => $this->taken);
        $collections = $this->createStub(CollectionFactory::class);
        $collections->method('create')->willReturn($collection);

        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([1 => $this->createStub(StoreInterface::class)]);

        return new FieldGroupManager(
            $factory,
            $this->resource,
            $collections,
            FieldGroupNormalizers::create($stores),
            $stores,
            new Json()
        );
    }

    private function field(string $id, string $name, array $extra = []): array
    {
        return $extra + ['id' => $id, 'kind' => 'field', 'type' => 'text', 'name' => $name, 'label' => ucfirst($name)];
    }

    public function testItSavesAGroupWhoseFieldsPassSchemaValidation(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->once())->method('save')->with($this->group);

        $manager->save([
            'name' => "  Contact\x07 block ",
            'description' => 'Name and email',
            'fields' => [$this->field('f_aaaa1', 'name', ['width' => 'bogus', 'evil' => 1])],
            'translations' => [1 => ['items' => ['f_aaaa1' => ['label' => ['t' => 'Nom', 's' => 'Name']]]]],
        ]);

        $this->assertSame('Contact block', $this->group->getData('name'));
        $fields = json_decode((string)$this->group->getData('fields_json'), true);
        $this->assertSame(['f_aaaa1'], array_column($fields, 'id'));
        $this->assertSame('full', $fields[0]['width']);
        $this->assertArrayNotHasKey('evil', $fields[0]);
        $this->assertSame('Nom', json_decode((string)$this->group->getData('translations_json'), true)[1]['items']['f_aaaa1']['label']['t']);
    }

    public function testItRejectsAGroupWithAnInvalidField(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->never())->method('save');

        try {
            $manager->save(['name' => 'Bad', 'fields' => [
                $this->field('f_aaaa1', 'ok'),
                $this->field('f_aaaa2', 'Not A Name!'),
                $this->field('f_aaaa3', 'ghost', ['type' => 'vanished']),
            ]]);
            $this->fail('Expected a validation error');
        } catch (LocalizedException $e) {
            $this->assertGreaterThanOrEqual(2, count(explode("\n", $e->getMessage())));
        }
    }

    public function testItRejectsAGroupConditionThatReferencesAFieldOutsideTheGroup(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->never())->method('save');
        $dependent = $this->field('f_aaaa1', 'second', ['condition' => [
            'match' => 'all', 'rules' => [['field' => 'first_in_form', 'operator' => 'equals', 'value' => 'x']],
        ]]);

        $this->expectException(LocalizedException::class);
        $manager->save(['name' => 'Dependent', 'fields' => [$dependent]]);
    }

    public function testItAcceptsAGroupConditionThatReferencesAnEarlierFieldInTheGroup(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->once())->method('save');
        $dependent = $this->field('f_aaaa2', 'second', ['condition' => [
            'match' => 'all', 'rules' => [['field' => 'first', 'operator' => 'equals', 'value' => 'x']],
        ]]);

        $manager->save(['name' => 'Dependent', 'fields' => [$this->field('f_aaaa1', 'first'), $dependent]]);

        $this->assertStringContainsString('"field":"first"', (string)$this->group->getData('fields_json'));
    }

    public function testItRequiresANameOfAtMostOneHundredTwentyCharacters(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->never())->method('save');
        $this->expectExceptionMessage('Enter a name');
        $manager->save(['name' => " \x00 ", 'fields' => [$this->field('f_aaaa1', 'first')]]);
    }

    public function testItCutsALongNameToOneHundredTwentyCharacters(): void
    {
        $manager = $this->manager();
        $manager->save(['name' => str_repeat('é', 200), 'fields' => [$this->field('f_aaaa1', 'first')]]);
        $this->assertSame(120, mb_strlen((string)$this->group->getData('name')));
    }

    public function testItRefusesANameAnotherGroupUses(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->taken = 1;
        $this->resource->expects($this->never())->method('save');
        $this->expectExceptionMessage('already exists');
        $manager->save(['name' => 'Contact', 'fields' => [$this->field('f_aaaa1', 'first')]]);
    }

    public function testItReportsANameTakenInTheMeantimeAsAValidationError(): void
    {
        $manager = $this->manager();
        $this->resource->method('save')->willThrowException(new AlreadyExistsException(__('dup')));
        $this->expectExceptionMessage('already exists');
        $manager->save(['name' => 'Contact', 'fields' => [$this->field('f_aaaa1', 'first')]]);
    }

    public function testItKeepsTheStoredFieldsWhenOnlyTheNameChanges(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->once())->method('save');

        $manager->save(['name' => 'Renamed', 'description' => 'new text'], 9);

        $this->assertSame('Renamed', $this->group->getData('name'));
        $this->assertSame('new text', $this->group->getData('description'));
        $this->assertSame('[{"id":"f_keep1"}]', $this->group->getData('fields_json'));
        $this->assertContains(['group_id', ['neq' => 9]], $this->filters);
    }

    public function testItFailsForAGroupThatDoesNotExist(): void
    {
        $manager = $this->manager();
        $this->expectException(NoSuchEntityException::class);
        $manager->save(['name' => 'X'], 77);
    }

    public function testItDeletesAGroupById(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->once())->method('delete');
        $manager->delete(9);
    }

    public function testItFailsToDeleteAGroupThatDoesNotExist(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->resource->expects($this->never())->method('delete');
        $this->expectException(NoSuchEntityException::class);
        $manager->delete(77);
    }

    public function testItReadsTheFieldsOfAStoredGroup(): void
    {
        $manager = $this->manager();
        $this->assertSame([['id' => 'f_keep1']], $manager->fieldsOf($manager->get(9)));
    }
}
