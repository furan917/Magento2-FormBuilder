<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Draft\DraftStore;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\PublicSchema;
use Furan\FormBuilder\Model\Submission\SummaryBuilder;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ActionPools;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use Furan\FormBuilder\Test\Unit\Helper\SmsConsentType;
use Furan\FormBuilder\Test\Unit\Helper\StampType;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Consent and server-value behaviour belongs to the capability interfaces, not to Newsletter and Hidden.
 */
class CapabilityInterfacesTest extends TestCase
{
    private function pool(): Pool
    {
        return new Pool([
            'text' => new Text(),
            SmsConsentType::CODE => new SmsConsentType(),
            StampType::CODE => new StampType(),
        ]);
    }

    private function normalizer(Pool $types): Normalizer
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([]);

        return new Normalizer(
            $types,
            new RulePool(),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues(new RedirectValidator($manager)),
            new PersonalData(FieldPools::builtIn()),
            new Sources(),
            ConditionNormalizers::builtIn([], $types)
        );
    }

    private function schema(array $target): array
    {
        $source = ['kind' => 'field', 'type' => 'text', 'name' => 'source', 'label' => 'Source'];
        $target += ['kind' => 'field', 'type' => SmsConsentType::CODE, 'name' => 'target', 'label' => 'Target'];

        return ['pages' => [['title' => 'P', 'items' => [$source, $target]]]];
    }

    /** @return string[] */
    private function errors(array $schema): array
    {
        try {
            $this->normalizer($this->pool())->normalize($schema);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    private function when(string $action): array
    {
        return ['actions' => [['action' => $action, 'param' => 'yes']], 'match' => 'all', 'rules' => [
            ['field' => 'source', 'operator' => 'equals', 'value' => 'x'],
        ]];
    }

    public function testItTreatsAnyConsentTypeAsNeverRequiredBlankedUnderLoginAsCustomerNotDraftableAndRefusedHideAndRequireActions(): void
    {
        $this->assertSame(
            ['Field "Target" cannot be required: consent must be freely given.'],
            $this->errors($this->schema(['required' => true]))
        );
        $this->assertSame(
            ['"Target": consent cannot be required or pre-filled by a condition.'],
            $this->errors($this->schema(['condition' => $this->when('require')]))
        );

        $stored = $this->normalizer($this->pool())->normalize($this->schema(['draft' => true]));
        $this->assertFalse($stored['pages'][0]['items'][1]['draft']);

        $store = new DraftStore($this->createStub(ResourceConnection::class), new Json(), $this->pool());
        $this->assertSame(
            ['name' => 'Ann'],
            $store->sanitize(
                ['pages' => [['items' => [
                    ['kind' => 'field', 'type' => 'text', 'name' => 'name'],
                    ['kind' => 'field', 'type' => SmsConsentType::CODE, 'name' => 'sms', 'draft' => true],
                ]]]],
                ['name' => 'Ann', 'sms' => 'yes']
            )
        );
        $this->assertTrue($this->pool()->isConsent(SmsConsentType::CODE));
        $this->assertFalse($this->pool()->isConsent('text'));
    }

    public function testItTreatsAnyServerValueTypeAsOverwritableAndNotDraftable(): void
    {
        $pool = $this->pool();
        $this->assertTrue($pool->isServerValue(StampType::CODE));
        $this->assertFalse($pool->isServerValue('text'));

        $store = new DraftStore($this->createStub(ResourceConnection::class), new Json(), $pool);
        $this->assertSame(
            [],
            $store->sanitize(
                ['pages' => [['items' => [['kind' => 'field', 'type' => StampType::CODE, 'name' => 'tag']]]]],
                ['tag' => 'x']
            )
        );

        $config = $this->createStub(Config::class);
        $public = (new PublicSchema(new RulePool(), $pool, $config))->build(['version' => 1, 'pages' => [[
            'id' => 'p_aaaa', 'title' => 'T', 'condition' => null, 'items' => [
                ['id' => 'f_aaaa', 'kind' => 'field', 'type' => StampType::CODE, 'name' => 'tag', 'label' => 'Tag', 'rules' => []],
            ],
        ]]]);
        $this->assertTrue($public['pages'][0]['items'][0]['overwritable']);

        $evaluator = new Evaluator(OperatorPools::builtIn(), $pool, ActionPools::builtIn());
        $state = $evaluator->computeState(['pages' => [['id' => 'p_aaaa', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a', 'label' => 'A'],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => StampType::CODE, 'name' => 'tag', 'label' => 'Tag', 'condition' => [
                'match' => 'all',
                'actions' => [['action' => 'set_value', 'param' => 'server']],
                'rules' => [['field' => 'a', 'operator' => 'equals', 'value' => 'x']],
            ]],
        ]]]], ['a' => 'x', 'tag' => 'posted']);
        $this->assertSame('server', $state->values()['tag']);

        $summary = new SummaryBuilder($pool, new \Furan\FormBuilder\Model\Submission\ValueFormatter($pool));
        $this->assertSame('Ann', $summary->build(
            [['name' => 'tag', 'type' => StampType::CODE], ['name' => 'name', 'type' => 'text']],
            ['tag' => 'secret', 'name' => 'Ann']
        ));
    }

    public function testItReadsBuilderDefaultsAndTheInvalidMessageFromAnyFieldTypeInterfaceImplementation(): void
    {
        $type = $this->createStub(FieldTypeInterface::class);
        $type->method('getBuilderDefaults')->willReturn(['label' => 'Pick']);
        $type->method('getInvalidMessage')->willReturn('Bad');

        $this->assertSame(['label' => 'Pick'], $type->getBuilderDefaults());
        $this->assertSame('Bad', $type->getInvalidMessage([]));
        $this->assertSame([], (new Text())->getBuilderDefaults());
        $this->assertNull((new Text())->getInvalidMessage([]));
    }
}
