<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Model\ResourceModel\SavedRule as SavedRuleResource;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\Collection as RuleCollection;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\CollectionFactory as RuleCollectionFactory;
use Furan\FormBuilder\Model\SavedRule;
use Furan\FormBuilder\Model\SavedRuleFactory;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RuleLibrary;
use Furan\FormBuilder\Model\Validation\SavedRuleManager;
use Furan\FormBuilder\Model\Validation\SavedRuleUsage;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use PHPUnit\Framework\TestCase;

class SavedRuleManagerTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private SavedRule $rule;
    private SavedRuleResource $resource;
    private RuleLibrary $library;
    private array $events = [];
    private int $taken = 0;

    private function manager(array $forms = []): SavedRuleManager
    {
        $this->rule = new class extends SavedRule {
            public ?int $storedId = null;

            public function __construct()
            {
            }

            public function getId(): ?int
            {
                return $this->storedId;
            }
        };
        $ruleFactory = $this->createStub(SavedRuleFactory::class);
        $ruleFactory->method('create')->willReturn($this->rule);
        $this->resource = $this->double('resource', SavedRuleResource::class);

        $ruleCollection = $this->createStub(RuleCollection::class);
        $ruleCollection->method('addFieldToFilter')->willReturnSelf();
        $ruleCollection->method('getSize')->willReturnCallback(fn () => $this->taken);
        $ruleCollections = $this->createStub(RuleCollectionFactory::class);
        $ruleCollections->method('create')->willReturn($ruleCollection);

        $usage = $this->createStub(SavedRuleUsage::class);
        $usage->method('forms')->willReturnCallback(static fn (string $code): array => array_values(array_map(
            static fn (array $f): array => ['id' => $f['id'], 'title' => $f['title']],
            array_filter($forms, static fn (array $f): bool => $f['code'] === $code)
        )));
        $usage->method('counts')->willReturnCallback(static function (array $codes) use ($forms): array {
            $counts = array_count_values(array_column($forms, 'code'));
            return array_intersect_key($counts, array_flip($codes));
        });

        $this->library = $this->double('library', RuleLibrary::class);
        $contexts = $this->createStub(CacheContextFactory::class);
        $contexts->method('create')->willReturnCallback(static fn () => new CacheContext());
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data) {
            $this->events[] = [$name, $data['object']->getIdentities()];
        });

        return new SavedRuleManager(
            $ruleFactory,
            $this->resource,
            $ruleCollections,
            $usage,
            new PatternMatcher(new RepeatAnalyzer()),
            $this->library,
            $contexts,
            $events
        );
    }

    private function formUsing(int $id, string $title, string $code): array
    {
        return ['id' => $id, 'title' => $title, 'code' => $code];
    }

    public function testItSavesANewRuleWithADefaultMessage(): void
    {
        $this->asserted = ['resource', 'library'];
        $manager = $this->manager();
        $this->resource->expects($this->once())->method('save')->with($this->rule);
        $this->library->expects($this->once())->method('reset');
        $manager->save(['label' => ' Order ref ', 'code' => 'Order_Ref', 'pattern' => '[A-Z]{2}[0-9]{4}', 'message' => '', 'example' => 'AB1234']);
        $this->assertSame('order_ref', $this->rule->getData('code'));
        $this->assertSame('Order ref', $this->rule->getData('label'));
        $this->assertSame('Please match the requested format.', $this->rule->getData('message'));
        $this->assertSame([], $this->events);
    }

    public function testItReportsEveryProblemAtOnce(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager();
        $this->taken = 1;
        $this->resource->expects($this->never())->method('save');
        try {
            $manager->save(['label' => '', 'code' => 'taken', 'pattern' => '(a+)+', 'message' => '']);
            $this->fail('Expected a validation error');
        } catch (LocalizedException $e) {
            $lines = explode("\n", $e->getMessage());
            $this->assertCount(3, $lines);
            $this->assertStringContainsString('Enter a name', $lines[0]);
            $this->assertStringContainsString('"taken" already exists', $lines[1]);
            $this->assertStringContainsString('pattern is invalid', $lines[2]);
        }
    }

    public function testItRejectsABadCodeAndAnExampleThatDoesNotMatch(): void
    {
        $manager = $this->manager();
        $this->expectExceptionMessageMatches('/code must start with a letter.*\n.*"abc" does not match/s');
        $manager->save(['label' => 'X', 'code' => '9bad', 'pattern' => '[0-9]+', 'example' => 'abc']);
    }

    public function testItKeepsTheCodeOfAnExistingRuleAndPurgesFormsThatUseIt(): void
    {
        $manager = $this->manager([
            $this->formUsing(3, 'Contact', 'ref'),
            $this->formUsing(4, 'Other', 'refs'),
            $this->formUsing(5, 'Events', 'ref'),
        ]);
        $this->rule->storedId = 9;
        $this->rule->setData('code', 'ref');
        $manager->save(['label' => 'Ref', 'code' => 'changed', 'pattern' => '[0-9]+'], 9);
        $this->assertSame('ref', $this->rule->getData('code'));
        $this->assertSame([['clean_cache_by_tags', ['furan_form_3', 'furan_form_5']]], $this->events);
    }

    public function testItRefusesToDeleteARuleThatFormsUse(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager([$this->formUsing(3, 'Contact', 'ref')]);
        $this->rule->storedId = 9;
        $this->rule->setData('code', 'ref');
        $this->resource->expects($this->never())->method('delete');
        $this->expectExceptionMessage('This rule is used by "Contact". Remove it from those fields first.');
        $manager->delete(9);
    }

    public function testItDeletesAnUnusedRuleAndCountsUsage(): void
    {
        $this->asserted = ['resource'];
        $manager = $this->manager([$this->formUsing(3, 'Contact', 'ref'), $this->formUsing(4, 'B', 'ref')]);
        $this->rule->storedId = 9;
        $this->rule->setData('code', 'other');
        $this->resource->expects($this->once())->method('delete');
        $manager->delete(9);
        $this->assertSame(['ref' => 2], $manager->usageCounts(['ref', 'other']));
    }
}
