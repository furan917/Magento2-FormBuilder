<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\ViewModel;

use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Furan\FormBuilder\Model\FieldGroup\GroupUsage;
use Furan\FormBuilder\ViewModel\FieldGroupEditor;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class FieldGroupEditorTest extends TestCase
{
    /** @var list<string> */
    private array $allowed = [];
    private mixed $persisted = null;
    private ?FieldGroup $group = null;
    private DataPersistorInterface $persistor;
    private GroupUsage $usage;

    private function editor(): FieldGroupEditor
    {
        $manager = $this->createStub(FieldGroupManager::class);
        if ($this->group === null) {
            $manager->method('get')->willThrowException(new NoSuchEntityException(__('gone')));
        } else {
            $manager->method('get')->willReturn($this->group);
        }
        $manager->method('fieldsOf')->willReturn([['id' => 'f_a', 'kind' => 'field', 'name' => 'email']]);
        $manager->method('translationsOf')->willReturn([1 => ['items' => ['f_a' => ['label' => 'E-mail']]]]);
        $this->persistor ??= $this->createStub(DataPersistorInterface::class);
        $this->persistor->method('get')->willReturnCallback(fn (string $key) => $key === PersistKeys::FIELD_GROUP ? $this->persisted : null);
        $this->usage ??= $this->createStub(GroupUsage::class);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(fn (string $resource): bool => in_array($resource, $this->allowed, true));
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $path, ?array $params = null): string => '/' . $path . ($params ? '/' . http_build_query($params) : '')
        );
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'id' ? '7' : null);
        return new FieldGroupEditor($manager, $this->usage, $this->persistor, $auth, $url, $request, new Json());
    }

    private function storedGroup(): FieldGroup
    {
        $group = $this->createStub(FieldGroup::class);
        $group->method('getData')->willReturnCallback(static fn (string $key) => ['name' => 'Contact', 'description' => 'Who'][$key] ?? null);
        return $group;
    }

    public function testItGivesTheBuilderTheGroupAsAOnePageForm(): void
    {
        $this->group = $this->storedGroup();

        $form = $this->editor()->getForm();

        $this->assertSame(7, $form['id']);
        $this->assertSame('Contact', $form['title']);
        $this->assertSame('Who', $form['description']);
        $this->assertSame([0], $form['store_ids']);
        $this->assertCount(1, $form['schema']['pages']);
        $this->assertSame(FieldGroupEditor::PAGE_ID, $form['schema']['pages'][0]['id']);
        $this->assertSame('email', $form['schema']['pages'][0]['items'][0]['name']);
        $this->assertSame([1 => ['items' => ['f_a' => ['label' => 'E-mail']]]], $form['translations']);
    }

    public function testItShowsTheInputOfAFailedSaveOnceAndIgnoresUnreadableParts(): void
    {
        $this->group = $this->storedGroup();
        $this->persisted = ['name' => 'Typed', 'description' => 'Kept', 'fields' => '[{"id":"f_b","kind":"field","name":"phone"}]', 'translations' => 'not json'];
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        $this->persistor->expects($this->once())->method('clear')->with(PersistKeys::FIELD_GROUP);
        $editor = $this->editor();

        $form = $editor->getForm();

        $this->assertSame('Typed', $form['title']);
        $this->assertSame('Kept', $form['description']);
        $this->assertSame('phone', $form['schema']['pages'][0]['items'][0]['name']);
        $this->assertSame([1 => ['items' => ['f_a' => ['label' => 'E-mail']]]], $form['translations']);
        $this->assertSame($form, $editor->getForm());
    }

    public function testItKeepsTheStoredFieldsWhenTheFailedSaveHadNoList(): void
    {
        $this->group = $this->storedGroup();
        $this->persisted = ['name' => 'Typed', 'fields' => '{"a":1}'];

        $this->assertSame('email', $this->editor()->getForm()['schema']['pages'][0]['items'][0]['name']);
    }

    public function testItListsTheFormsUsingTheGroupLinkedOnlyForAdminsWhoMayOpenForms(): void
    {
        $this->usage = $this->createStub(GroupUsage::class);
        $this->usage->method('formsUsing')->willReturn(['rows' => [['id' => 3, 'title' => 'Contact']], 'total' => 1]);

        $this->assertSame(['rows' => [['title' => 'Contact', 'url' => '']], 'total' => 1], $this->editor()->getUsage());

        $this->allowed = ['Furan_FormBuilder::forms'];
        $this->assertSame('/furanform/form/edit/id=3', $this->editor()->getUsage()['rows'][0]['url']);
    }

    public function testItOffersGroupInsertionOnlyToAdminsWhoMayEdit(): void
    {
        $this->assertFalse($this->editor()->canEdit());
        $this->assertSame([], $this->editor()->getFieldGroupUrls());

        $this->allowed = ['Furan_FormBuilder::field_groups_edit'];
        $editor = $this->editor();
        $this->assertTrue($editor->canEdit());
        $this->assertFalse($editor->canDelete());
        $this->assertSame(['list', 'insert'], array_keys($editor->getFieldGroupUrls()));
    }

    public function testItPointsSaveDeleteAndBackAtTheGroupPages(): void
    {
        $editor = $this->editor();

        $this->assertSame('/furanform/fieldgroup/save', $editor->getSaveUrl());
        $this->assertSame('/furanform/fieldgroup/delete', $editor->getDeleteUrl());
        $this->assertSame('/furanform/fieldgroup/index', $editor->getBackUrl());
    }
}
