<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldGroup;

use Furan\FormBuilder\Test\Unit\Helper\ModelFixtures;
use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\FieldGroup\BuilderGroups;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupList;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class BuilderGroupsTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private FieldGroupManager&Stub $manager;
    private FieldGroupList&Stub $list;

    private function service(): BuilderGroups
    {
        $this->manager = $this->double('manager', FieldGroupManager::class);
        $this->list = $this->double('list', FieldGroupList::class);
        return new BuilderGroups($this->manager, $this->list, new Json());
    }

    private function group(int $id, string $name): FieldGroup
    {
        return ModelFixtures::model(FieldGroup::class, $id, ['name' => $name]);
    }

    public function testItSavesTheSelectedSectionAsAGroup(): void
    {
        $this->asserted = ['manager'];
        $groups = $this->service();
        $fields = [['id' => 's_addr0001', 'kind' => 'section', 'items' => []]];
        $this->manager->expects($this->once())->method('save')
            ->with(['name' => 'Address', 'description' => 'Two lines', 'fields' => $fields, 'translations' => ['1' => []]], null)
            ->willReturn($this->group(5, 'Address'));

        $result = $groups->create(json_encode([
            'name' => 'Address', 'description' => 'Two lines', 'fields' => $fields, 'translations' => ['1' => []],
            'group_id' => 99, 'id' => 99,
        ]));

        $this->assertSame([200, ['success' => true, 'id' => 5, 'name' => 'Address']], $result);
    }

    public function testItNeverTakesAGroupIdFromTheClient(): void
    {
        $this->asserted = ['manager'];
        $groups = $this->service();
        $this->manager->expects($this->once())->method('save')->with($this->anything(), null)->willReturn($this->group(6, 'x'));

        $groups->create(json_encode(['id' => 3, 'group_id' => 3, 'name' => 'x', 'fields' => [[]]]));
    }

    public function testItListsEveryProblemOfAnInvalidGroup(): void
    {
        $groups = $this->service();
        $this->manager->method('save')->willThrowException(new LocalizedException(__("Enter a name.\nBad field.")));

        $this->assertSame(
            [422, ['success' => false, 'errors' => ['Enter a name.', 'Bad field.']]],
            $groups->create(json_encode(['name' => '', 'fields' => [[]]]))
        );
    }

    public function testItRefusesAnOversizedOrMalformedPayloadBeforeDecoding(): void
    {
        $this->asserted = ['manager'];
        $groups = $this->service();
        $this->manager->expects($this->never())->method('save');

        $this->assertSame(413, $groups->create(str_repeat('a', BuilderGroups::MAX_PAYLOAD_BYTES + 1))[0]);
        $this->assertSame(400, $groups->create('{not json')[0]);
        $this->assertSame(400, $groups->create('[1,2]')[0]);
        $this->assertSame(400, $groups->create(['array'])[0]);
    }

    public function testItListsGroupsForThePaletteWithoutTheirFields(): void
    {
        $this->asserted = ['list'];
        $groups = $this->service();
        $rows = [['id' => 1, 'name' => 'A', 'description' => 'd', 'updated_at' => '2026-01-01 00:00:00']];
        $this->list->expects($this->once())->method('page')->with(7, 20)->willReturn(['rows' => $rows, 'next' => 1]);

        $this->assertSame(
            [200, ['success' => true, 'groups' => [['id' => 1, 'name' => 'A', 'description' => 'd']], 'next' => 1]],
            $groups->palette(7)
        );
    }

    public function testItReturnsAGroupsFieldsAndTranslationsForInsertion(): void
    {
        $this->asserted = ['manager'];
        $groups = $this->service();
        $group = $this->group(4, 'Address');
        $this->manager->method('get')->with(4)->willReturn($group);
        $this->manager->method('fieldsOf')->with($group)->willReturn([['id' => 'f_a']]);
        $this->manager->method('translationsOf')->with($group)->willReturn([]);

        $this->assertSame(
            [200, ['success' => true, 'id' => 4, 'name' => 'Address', 'fields' => [['id' => 'f_a']], 'translations' => []]],
            $groups->fetch(4)
        );
    }

    public function testItReportsAGroupThatNoLongerExists(): void
    {
        $groups = $this->service();
        $this->manager->method('get')->willThrowException(new NoSuchEntityException(__('gone')));

        $this->assertSame(404, $groups->fetch(4)[0]);
        $this->assertSame(404, $groups->fetch(0)[0]);
    }
}
