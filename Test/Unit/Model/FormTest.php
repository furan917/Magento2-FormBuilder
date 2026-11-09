<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class FormTest extends TestCase
{
    private function createForm(): Form
    {
        return new Form(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $this->createStub(FormResource::class)
        );
    }

    public function testItStoresTheSchemaAsJsonAndReturnsItAsAnArray(): void
    {
        $form = $this->createForm();
        $schema = ['version' => 1, 'pages' => [['id' => 'p_ab12cd', 'items' => []]]];

        $form->setSchema($schema);

        $this->assertSame(json_encode($schema), $form->getData('schema_json'));
        $this->assertSame($schema, $form->getSchema());
    }

    public function testItReturnsAnEmptyArrayWhenStoredSchemaJsonIsInvalid(): void
    {
        $form = $this->createForm();
        $form->setData('schema_json', '{not json');
        $form->setData('settings_json', '"scalar"');

        $this->assertSame([], $form->getSchema());
        $this->assertSame([], $form->getSettings());
    }

    public function testItDecodesAgainOnlyWhenTheStoredJsonChanges(): void
    {
        $form = $this->createForm();
        $form->setSchema(['version' => 1]);
        $schema = $form->getSchema();
        $schema['version'] = 9;

        $this->assertSame(['version' => 1], $form->getSchema());
        $form->setData('schema_json', '{"version":2}');
        $this->assertSame(['version' => 2], $form->getSchema());
        $form->setSchema(['version' => 3]);
        $this->assertSame(['version' => 3], $form->getSchema());
        $form->setData('schema_json', null);
        $this->assertSame([], $form->getSchema());
        $form->setSettings(['seo_noindex' => true]);
        $this->assertSame(['seo_noindex' => true], $form->getSettings());
        $this->assertSame([], $form->getSchema());
    }

    public function testItStoresUnicodeAndSlashesUnescapedAndReplacesInvalidUtf8(): void
    {
        $form = $this->createForm();
        $form->setSettings(['url' => "https://x.test/caf\u{e9}", 'bad' => "a\xB1b"]);

        $this->assertSame("{\"url\":\"https://x.test/caf\u{e9}\",\"bad\":\"a\u{fffd}b\"}", $form->getData('settings_json'));
    }

    public function testItExposesCacheIdentitiesForASavedForm(): void
    {
        $form = $this->createForm();
        $form->setId(7);

        $this->assertSame(['furan_form_7'], $form->getIdentities());
        $this->assertSame(['furan_form'], $this->createForm()->getIdentities());
    }
}
