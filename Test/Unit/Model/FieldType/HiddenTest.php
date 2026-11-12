<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class HiddenTest extends TestCase
{
    private function normalizer(Hidden $hidden): Normalizer
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([$store]);

        return new Normalizer(
            new Pool(['hidden' => $hidden]),
            new RulePool(),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues(new RedirectValidator($manager)),
            new PersonalData(FieldPools::builtIn()),
            new Sources(),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn(),
            \Furan\FormBuilder\Test\Unit\Helper\FieldChecksFactory::create(),
        new \Furan\FormBuilder\Model\Validation\PresetFit()
);
    }

    private function sourceAfterSave(Hidden $hidden, string $source): string
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'hidden', 'name' => 'h', 'label' => 'H', 'settings' => ['source' => $source]],
        ]]]];

        return $this->normalizer($hidden)->normalize($schema)['pages'][0]['items'][0]['settings']['source'];
    }

    public function testItShowsTheQueryParameterSettingOnlyForTheQuerySource(): void
    {
        $this->assertSame(['source' => ['query'], '@prefill' => ['']], (new Hidden())->getSettingsSpec()['query_param']['visible_when']);
    }

    public function testItExplainsWhereEachSourceGetsItsValue(): void
    {
        $hint = (new Hidden())->getSettingsSpec()['source']['hint'];

        $this->assertStringContainsString('Default value', $hint);
        $this->assertStringContainsString('browser', $hint);
        $this->assertStringContainsString('Prefill source replaces this setting', $hint);
    }

    public function testItFixesThePrefilledAnswerToTheDefaultWhateverTheValueSource(): void
    {
        $hidden = new Hidden();
        $field = ['type' => 'hidden', 'default' => 'd', 'prefill' => 'customer.email'];

        $this->assertSame('d', $hidden->fixedValue($field + ['settings' => ['source' => 'query', 'query_param' => 'q']]));
        $this->assertSame('d', $hidden->fixedValue($field + ['settings' => ['source' => 'referrer']]));
        $this->assertNull($hidden->fixedValue(['prefill' => '', 'default' => 'd', 'settings' => ['source' => 'referrer']]));
    }

    public function testItCutsAStoredAnswerToTheHiddenMaximumLengthOf255Characters(): void
    {
        $this->assertSame(str_repeat('a', 255), (new Hidden())->normalizeValue(str_repeat('a', 300), []));
    }

    public function testItKeepsTheSettingsOfASavedHiddenFieldFreeOfSpecMetadata(): void
    {
        $schema = ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'hidden', 'name' => 'h', 'label' => 'H', 'settings' => ['source' => 'static']],
        ]]]];

        $settings = $this->normalizer(new Hidden())->normalize($schema)['pages'][0]['items'][0]['settings'];

        $this->assertSame(['source', 'query_param'], array_keys($settings));
    }

    public function testItAcceptsAHiddenSourceCodeAddedThroughDiWhenTheFormIsSaved(): void
    {
        $this->assertSame('campaign.cookie', $this->sourceAfterSave(new Hidden(['campaign.cookie']), 'campaign.cookie'));
        $this->assertSame('static', $this->sourceAfterSave(new Hidden(), 'campaign.cookie'));
    }

    public function testItAppendsValidExtraSourcesAfterTheBuiltInsAndIgnoresTheRest(): void
    {
        $hidden = new Hidden(['query', 'Bad Code', 'ok_one', 'ok_one', 5]);

        $this->assertSame(
            ['static', 'query', 'page_url', 'referrer', 'ok_one'],
            $hidden->getSettingsSpec()['source']['values']
        );
    }

    public function testItShowsTranslatedLabelsForCustomHiddenSourcesInTheBuilder(): void
    {
        $hidden = new Hidden(['plain.code', ['code' => 'crm.id', 'label' => 'CRM contact id'], ['code' => 'no.label']]);
        $source = $hidden->getSettingsSpec()['source'];

        $this->assertSame(
            ['static', 'query', 'page_url', 'referrer', 'plain.code', 'crm.id', 'no.label'],
            $source['values']
        );
        $this->assertSame('CRM contact id', $source['value_labels']['crm.id']);
        $this->assertSame('Fixed value', $source['value_labels']['static']);
        $this->assertSame('Query parameter', $source['value_labels']['query']);
        $this->assertSame('Page URL', $source['value_labels']['page_url']);
        $this->assertSame('Referrer', $source['value_labels']['referrer']);
        $this->assertArrayNotHasKey('plain.code', $source['value_labels']);
        $this->assertArrayNotHasKey('no.label', $source['value_labels']);
    }

    public function testItSkipsACustomHiddenSourceWithAnInvalidCodeOrLabel(): void
    {
        $hidden = new Hidden([
            ['code' => 'Bad Code', 'label' => 'Fine'],
            ['code' => 'blank.label', 'label' => '   '],
            ['code' => 'array.label', 'label' => ['x']],
            ['code' => 'long.label', 'label' => str_repeat('x', 101)],
            ['code' => 'control.label', 'label' => "a\nb"],
            ['label' => 'No code'],
            ['code' => 'static', 'label' => 'Shadow'],
            ['code' => 'good.one', 'label' => ' Good '],
        ]);
        $source = $hidden->getSettingsSpec()['source'];

        $this->assertSame(['static', 'query', 'page_url', 'referrer', 'good.one'], $source['values']);
        $this->assertSame('Good', $source['value_labels']['good.one']);
        $this->assertSame('Fixed value', $source['value_labels']['static']);
    }
}
