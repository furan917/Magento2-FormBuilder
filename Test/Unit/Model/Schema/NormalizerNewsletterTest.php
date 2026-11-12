<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\Email;
use Furan\FormBuilder\Model\FieldType\Newsletter;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Newsletter\NewsletterBridge;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\WorkEmailType;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerNewsletterTest extends TestCase
{
    private function normalizer(bool $available, array $extraTypes = []): Normalizer
    {
        $bridge = $this->createStub(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn($available);
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([]);

        return new Normalizer(
            new Pool($extraTypes + ['text' => new Text(), 'email' => new Email(), 'newsletter' => new Newsletter($bridge)]),
            new RulePool([]),
            new PatternMatcher(new RepeatAnalyzer()),
            new SettingValues(new RedirectValidator($manager)),
            new PersonalData(FieldPools::builtIn()),
            \Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools::builtIn([
                'customer.loyalty' => new \Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource(),
            ]),
            ConditionNormalizers::builtIn(),
            \Furan\FormBuilder\Test\Unit\Helper\FieldChecksFactory::create(),
        new \Furan\FormBuilder\Model\Validation\PresetFit()
);
    }

    private function newsletter(array $over = []): array
    {
        return $over + ['id' => 'f_news01', 'kind' => 'field', 'type' => 'newsletter', 'name' => 'news', 'label' => 'News'];
    }

    private function schema(array ...$items): array
    {
        return ['pages' => [['items' => $items]]];
    }

    private function errors(Normalizer $normalizer, array $schema, ?array $stored = null): array
    {
        try {
            $normalizer->normalize($schema, $stored);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    public function testItRefusesANewNewsletterFieldWhenTheModuleIsUnavailable(): void
    {
        $errors = $this->errors($this->normalizer(false), $this->schema($this->newsletter()));

        $this->assertStringContainsString('News', implode(' ', $errors));
    }

    public function testItKeepsAStoredNewsletterFieldWhenTheModuleIsLaterDisabled(): void
    {
        $stored = $this->normalizer(true)->normalize($this->schema($this->newsletter(['help'=>'Stored help'])));
        $posted = $this->schema($this->newsletter(['help' => 'Tampered']));

        $out = $this->normalizer(false)->normalize($posted, $stored);

        $this->assertSame($stored['pages'][0]['items'][0], $out['pages'][0]['items'][0]);
    }

    public function testItRefusesToMakeANewsletterFieldRequired(): void
    {
        $errors = $this->errors($this->normalizer(true), $this->schema($this->newsletter(['required' => true])));

        $this->assertStringContainsString('cannot be required', implode(' ', $errors));
    }

    public function testItNeverStoresANewsletterFieldPreTickedOrDrafted(): void
    {
        $out = $this->normalizer(true)->normalize(
            $this->schema($this->newsletter(['default' => 'yes', 'draft' => true]))
        );

        $field = $out['pages'][0]['items'][0];
        $this->assertSame('', $field['default']);
        $this->assertFalse($field['draft']);
        $this->assertFalse($field['required']);
    }

    public function testItAcceptsAThirdPartyEmailAddressTypeAsTheEmailField(): void
    {
        $normalizer = $this->normalizer(true, [WorkEmailType::CODE => new WorkEmailType()]);
        $work = ['id' => 'f_work01', 'kind' => 'field', 'type' => WorkEmailType::CODE, 'name' => 'work', 'label' => 'Work'];

        $out = $normalizer->normalize($this->schema($work, $this->newsletter(['settings' => ['email_field' => 'work']])));

        $this->assertSame('work', $out['pages'][0]['items'][1]['settings']['email_field']);
    }

    public function testItRejectsAnEmailFieldSettingThatNamesAFieldWhichIsNotAnEmailField(): void
    {
        $normalizer = $this->normalizer(true);
        $mail = ['id' => 'f_mail01', 'kind' => 'field', 'type' => 'email', 'name' => 'mail', 'label' => 'Mail'];
        $text = ['id' => 'f_text01', 'kind' => 'field', 'type' => 'text', 'name' => 'plain', 'label' => 'Plain'];

        $ok = $normalizer->normalize($this->schema($mail, $this->newsletter(['settings' => ['email_field' => 'mail']])));
        $empty = $normalizer->normalize($this->schema($this->newsletter()));
        $notEmail = $this->errors($normalizer, $this->schema($text, $this->newsletter(['settings' => ['email_field' => 'plain']])));
        $unknown = $this->errors($normalizer, $this->schema($mail, $this->newsletter(['settings' => ['email_field' => 'ghost']])));

        $this->assertSame('mail', $ok['pages'][0]['items'][1]['settings']['email_field']);
        $this->assertSame('', $empty['pages'][0]['items'][0]['settings']['email_field']);
        $this->assertStringContainsString('email field', implode(' ', $notEmail));
        $this->assertStringContainsString('email field', implode(' ', $unknown));
    }

    public function testItNeverPrefillsOrLocksANewsletterField(): void
    {
        $out = $this->normalizer(true)->normalize(
            $this->schema($this->newsletter(['prefill' => 'customer.loyalty', 'locked' => true]))
        );

        $field = $out['pages'][0]['items'][0];
        $this->assertSame('', $field['prefill']);
        $this->assertFalse($field['locked']);
    }

    public function testItReportsAnErrorInsteadOfFailingWhenTheEmailFieldNamesAKeptUnknownField(): void
    {
        $normalizer = $this->normalizer(true);
        $ghost = ['id' => 'f_ghost1', 'kind' => 'field', 'type' => 'gone', 'name' => 'ghost', 'label' => 'Ghost'];
        $stored = ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'title' => '', 'condition' => null, 'items' => [$ghost]]]];

        $errors = $this->errors(
            $normalizer,
            $this->schema($ghost, $this->newsletter(['settings' => ['email_field' => 'ghost']])),
            $stored
        );

        $this->assertStringContainsString('email field', implode(' ', $errors));
    }
}
