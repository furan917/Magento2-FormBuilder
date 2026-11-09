<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Storefront;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\PublicSchema;
use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Furan\FormBuilder\Model\Storefront\ConfigBuilder;
use Furan\FormBuilder\Model\Storefront\Eagerness;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigBuilderTest extends TestCase
{
    private ?\Closure $observer = null;

    public function testItKeepsTheDraftKeyWhenALabelHelpTextOrOptionLabelChanges(): void
    {
        $before = $this->key($this->schema());
        $edited = $this->schema();
        $edited['pages'][0]['title'] = 'Renamed page';
        $edited['pages'][0]['items'][0]['label'] = 'New label';
        $edited['pages'][0]['items'][0]['help'] = 'New help';
        $edited['pages'][0]['items'][1]['options'] = [['value' => 'a', 'label' => 'Changed']];

        $this->assertSame($before, $this->key($edited));
        $this->assertMatchesRegularExpression('/^ffb:1:12:[0-9a-f]{12}$/', $before);
    }

    public function testItChangesTheDraftKeyWhenADraftableFieldIsAddedRemovedRenamedRetypedOrOptedOutOfDrafts(): void
    {
        $base = $this->key($this->schema());
        $variants = [];

        $added = $this->schema();
        $added['pages'][0]['items'][] = ['kind' => 'field', 'name' => 'extra', 'type' => 'text', 'draft' => true];
        $variants['added'] = $added;

        $removed = $this->schema();
        unset($removed['pages'][0]['items'][1]);
        $variants['removed'] = $removed;

        $renamed = $this->schema();
        $renamed['pages'][0]['items'][0]['name'] = 'full_name';
        $variants['renamed'] = $renamed;

        $retyped = $this->schema();
        $retyped['pages'][0]['items'][0]['type'] = 'textarea';
        $variants['retyped'] = $retyped;

        $optedOut = $this->schema();
        $optedOut['pages'][0]['items'][0]['draft'] = false;
        $variants['opted out'] = $optedOut;

        $keys = [$base];
        foreach ($variants as $label => $schema) {
            $key = $this->key($schema);
            $this->assertNotContains($key, $keys, $label);
            $keys[] = $key;
        }
    }

    public function testItIgnoresFieldsThatAreNeverDraftedWhenBuildingTheDraftKey(): void
    {
        $withHidden = $this->schema();
        $withHidden['pages'][0]['items'][] = ['kind' => 'field', 'name' => 'src', 'type' => 'hidden', 'draft' => false];

        $this->assertSame($this->key($this->schema()), $this->key($withHidden));
    }

    public function testItRestoresTheProtectedConfigKeysAfterObservers(): void
    {
        $this->observer = static function (\Magento\Framework\DataObject $config): void {
            $config->setData('crm', ['campaign' => 'spring']);
            $config->unsetData('formId');
            $config->setData('submitUrl', 'https://evil.test/');
            $config->unsetData('schema');
            $config->setData('tokenUrl', 'https://evil.test/token');
        };

        $config = $this->build($this->schema());

        $this->assertSame(['campaign' => 'spring'], $config['crm']);
        $this->assertSame(12, $config['formId']);
        $this->assertSame('url:furanform/form/submit', $config['submitUrl']);
        $this->assertSame('url:furanform/form/token', $config['tokenUrl']);
        $this->assertSame(['public' => true] + $this->schema(), $config['schema']);
    }

    /** @param array<string, mixed> $schema */
    private function key(array $schema): string
    {
        return $this->build($schema)['draft']['key'];
    }

    public function testItShowsTheReferenceLineUnlessSwitchedOffOrNothingIsStored(): void
    {
        $this->assertTrue($this->build($this->schema())['showReference']);
        $this->assertTrue($this->build($this->schema(), ['show_reference' => true, 'store_submissions' => true])['showReference']);
        $this->assertFalse($this->build($this->schema(), ['show_reference' => false])['showReference']);
        $this->assertFalse($this->build($this->schema(), ['show_reference' => true, 'store_submissions' => false])['showReference']);
    }

    public function testItSendsTheReferenceLineTexts(): void
    {
        $messages = $this->build($this->schema())['messages'];

        $this->assertSame('Your reference', $messages['reference']);
        $this->assertSame('Copy', $messages['copy']);
        $this->assertSame('Copied', $messages['copied']);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function build(array $schema, array $settings = ['draft_mode' => 'session']): array
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(12);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getSettings')->willReturn($settings);
        $publicSchema = $this->createStub(PublicSchema::class);
        $publicSchema->method('build')->willReturnCallback(static fn (array $s): array => ['public' => true] + $s);
        $reCaptcha = $this->createStub(ReCaptcha::class);
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data): void {
            if ($this->observer !== null) {
                ($this->observer)($data['config']);
            }
        });
        $pool = new Pool([]);

        return (new ConfigBuilder(
            $publicSchema,
            $pool,
            $this->createStub(Config::class),
            new Json(),
            $this->createStub(LoggerInterface::class),
            $reCaptcha,
            new Eagerness(new Sources(), $pool),
            $events
        ))->build(
            $form,
            $schema,
            1,
            null,
            true,
            static fn (string $route, array $query = []): string => 'url:' . $route,
            static fn (string $fileId): string => 'asset:' . $fileId
        );
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return ['pages' => [['id' => 'p_aaaa', 'title' => 'Page', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'name' => 'name', 'type' => 'text', 'label' => 'Name', 'draft' => true],
            ['id' => 'f_bbbb', 'kind' => 'field', 'name' => 'pick', 'type' => 'radio', 'draft' => true,
                'options' => [['value' => 'a', 'label' => 'A']]],
            ['id' => 'c_aaaa', 'kind' => 'content', 'type' => 'paragraph', 'text' => 'Hi'],
        ]]]];
    }
}
