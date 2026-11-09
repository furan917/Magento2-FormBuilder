<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\SettingsNormalizer;
use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Settings\ContributorPool;
use Furan\FormBuilder\Test\Unit\Helper\ArraySettingsContributor;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\WorkEmailType;
use PHPUnit\Framework\TestCase;

class SettingsNormalizerTest extends TestCase
{
    private function normalizer(bool $redirectAllowed = true, ?Pool $pool = null, ?ContributorPool $contributors = null): SettingsNormalizer
    {
        $validator = $this->createStub(RedirectValidator::class);
        $validator->method('isAllowed')->willReturn($redirectAllowed);
        return new SettingsNormalizer(
            $validator,
            $pool ?? FieldPools::builtIn(),
            new SettingValues($validator),
            $contributors ?? ArraySettingsContributor::pool(),
            ConditionNormalizers::builtIn([], $pool)
        );
    }

    private function schema(): array
    {
        return ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'email', 'name' => 'mail'],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'name'],
        ]]]];
    }

    private function errorsFor(array $raw, bool $redirectAllowed = true): array
    {
        try {
            $this->normalizer($redirectAllowed)->normalize($raw, $this->schema());
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        return [];
    }

    public function testItDoesNotTalkAboutEarlierFieldsWhenARoutingConditionNamesAMissingField(): void
    {
        $expected = ['"Notifications": a condition names a field that is not in the form or cannot be routed.'];

        $this->assertSame($expected, $this->errorsFor(['notify_when' => $this->condition('ghost')]));
    }

    public function testItKeepsTheReferenceLineSwitchedOffWhenSaved(): void
    {
        $this->assertFalse($this->normalizer()->normalize(['show_reference' => false], $this->schema())['show_reference']);
        $this->assertFalse($this->normalizer()->normalize(['show_reference' => '0'], $this->schema())['show_reference']);
    }

    public function testItFillsSettingsDefaults(): void
    {
        $result = $this->normalizer()->normalize(['bogus' => 'x'], $this->schema());

        $this->assertSame([
            'submit_label' => 'Submit',
            'success_message' => 'Thank you, your form has been sent.',
            'success_redirect' => '',
            'show_reference' => true,
            'store_submissions' => true,
            'notify_enabled' => false,
            'notify_recipients' => [],
            'notify_subject' => '',
            'notify_cc' => [],
            'notify_bcc' => [],
            'notify_routes' => [],
            'notify_when' => null,
            'autoresponder_enabled' => false,
            'autoresponder_email_field' => '',
            'email_field' => '',
            'access_login_required' => false,
            'access_groups' => [],
            'autoresponder_subject' => '',
            'autoresponder_body' => '',
            'autoresponder_when' => null,
            'draft_mode' => 'session',
            'store_ip' => false,
            'show_progress' => true,
            'seo_noindex' => false,
            'prev_label' => 'Back',
            'next_label' => 'Next',
            'page_title' => '',
            'meta_description' => '',
            'style' => 'module',
            'accent_color' => '',
            'survey_mode' => 'off',
            'guest_dedupe' => false,
            'account_edit' => false,
            'account_delete' => true,
            'customer_replies' => 'default',
            'open_from' => '',
            'close_at' => '',
            'schedule_timezone' => '',
            'closed_message' => 'This form is closed.',
            'not_open_message' => 'This form is not open yet.',
        ], $result);
    }

    public function testItNormalisesAccessGroups(): void
    {
        $out = fn(array $raw) => $this->normalizer()->normalize($raw, $this->schema());

        $this->assertSame([0, 1, 4], $out(['access_groups' => ['1', 1, 0, '4', -2, 'x', 32000]])['access_groups']);
        $this->assertSame([], $out(['access_groups' => 'nope'])['access_groups']);
        $this->assertSame([1], $out(['access_login_required' => true, 'access_groups' => [0, 1]])['access_groups']);
        $this->assertTrue($out(['access_login_required' => 'true'])['access_login_required']);
    }

    public function testItValidatesStyleAndAccentColour(): void
    {
        $result = $this->normalizer()->normalize(['style' => 'theme', 'accent_color' => ' #A1B2C3 '], $this->schema());
        $this->assertSame('theme', $result['style']);
        $this->assertSame('#a1b2c3', $result['accent_color']);
        $this->assertNotEmpty($this->errorsFor(['style' => 'inline']));
        foreach (['red', '#fff', '#12345g', '#123456;color:red', '#123456"', ['#123456']] as $bad) {
            $this->assertNotEmpty($this->errorsFor(['accent_color' => $bad]));
        }
    }

    public function testItRejectsInvalidNotificationRecipients(): void
    {
        $this->assertNotEmpty($this->errorsFor(['notify_recipients' => ['ok@example.com', 'nope']]));
        $this->assertNotEmpty($this->errorsFor(['notify_cc' => ["a@example.com\r\nBcc: x@y.com"]]));
        $this->assertNotEmpty($this->errorsFor(['notify_bcc' => array_map(static fn (int $i): string => "u$i@example.com", range(1, 11))]));
        $ok = $this->normalizer()->normalize(['notify_recipients' => [' a@example.com ']], $this->schema());
        $this->assertSame(['a@example.com'], $ok['notify_recipients']);
    }

    public function testItNamesSettingsByTheirBuilderLabelsInErrors(): void
    {
        $this->assertSame(['"Recipients" contains an invalid email address.'], $this->errorsFor(['notify_recipients' => ['nope']]));
        $this->assertSame(['"CC" contains an invalid email address.'], $this->errorsFor(['notify_cc' => ['nope']]));
        $this->assertSame(['"BCC" contains an invalid email address.'], $this->errorsFor(['notify_bcc' => ['nope']]));
        $this->assertSame(['"Recipients" must be a list of email addresses.'], $this->errorsFor(['notify_recipients' => 5]));
        $this->assertSame(
            ['The subject for "Notifications" must be a single line of at most 255 characters.'],
            $this->errorsFor(['notify_subject' => "a\nb"])
        );
        $this->assertSame(
            ['The subject for "Autoresponder" must be a single line of at most 255 characters.'],
            $this->errorsFor(['autoresponder_subject' => "a\nb"])
        );
        $this->assertSame(['"Routing rules" can contain at most 20 rules.'], $this->errorsFor(['notify_routes' => 'x']));
        $route = ['condition' => $this->condition('name'), 'emails' => ['nope'], 'mode' => 'add'];
        $this->assertSame(['"Routing rules" contains an invalid email address.'], $this->errorsFor(['notify_routes' => [$route]]));
        $this->assertSame(
            ['"Autoresponder": a condition names a field that is not in the form or cannot be routed.'],
            $this->errorsFor(['autoresponder_when' => $this->condition('ghost')])
        );
    }

    public function testItNamesContributorSettingsByTheirSpecLabelsInErrors(): void
    {
        $contributor = new ArraySettingsContributor('acme_crm', [
            'tags' => ['type' => 'list', 'default' => [], 'label' => 'Tags', 'min_items' => 1],
            'bare' => ['type' => 'list', 'default' => [], 'min_items' => 1],
        ]);
        try {
            $this->normalizer(true, null, ArraySettingsContributor::pool($contributor))->normalize([], $this->schema());
            $this->fail('Expected the empty lists to be refused.');
        } catch (InvalidSchemaException $e) {
            $this->assertSame([
                'Settings "Acme CRM": "Tags" needs at least 1 entries.',
                'Settings "Acme CRM": "bare" needs at least 1 entries.',
            ], $e->getErrors());
        }
    }

    public function testItRejectsHeaderInjectionInSubjects(): void
    {
        $this->assertNotEmpty($this->errorsFor(['notify_subject' => "Hi\r\nBcc: evil@example.com"]));
        $this->assertNotEmpty($this->errorsFor(['autoresponder_subject' => "Hi\nX: y"]));
        $this->assertNotEmpty($this->errorsFor(['notify_subject' => str_repeat('a', 256)]));
    }

    public function testItRequiresTheAutoresponderFieldToBeAnEmailField(): void
    {
        $this->assertNotEmpty($this->errorsFor(['autoresponder_enabled' => true, 'autoresponder_email_field' => 'name']));
        $this->assertNotEmpty($this->errorsFor(['autoresponder_enabled' => true, 'autoresponder_email_field' => 'missing']));
        $ok = $this->normalizer()->normalize(
            ['autoresponder_enabled' => true, 'autoresponder_email_field' => 'mail'],
            $this->schema()
        );
        $this->assertSame('mail', $ok['autoresponder_email_field']);
    }

    public function testItKeepsAnEmailFieldSettingOnlyWhenItNamesAnEmailField(): void
    {
        $keep = fn($v) => $this->normalizer()->normalize(['email_field' => $v], $this->schema())['email_field'];

        $this->assertSame('mail', $keep(' mail '));
        $this->assertSame('', $keep('name'));
        $this->assertSame('', $keep('missing'));
        $this->assertSame('', $keep(['mail']));
    }

    public function testItRejectsADisallowedRedirectAndBadDraftMode(): void
    {
        $this->assertNotEmpty($this->errorsFor(['success_redirect' => 'https://evil.test'], false));
        $this->assertNotEmpty($this->errorsFor(['draft_mode' => 'cookie']));
    }

    public function testItStripsTagsAndCapsLengths(): void
    {
        $result = $this->normalizer()->normalize(
            ['submit_label' => '<b>Go</b>' . str_repeat('x', 100), 'success_message' => str_repeat('m', 2500)],
            $this->schema()
        );
        $this->assertSame(50, mb_strlen($result['submit_label']));
        $this->assertStringStartsWith('Go', $result['submit_label']);
        $this->assertSame(2000, mb_strlen($result['success_message']));
    }

    public function testItRejectsSurveyModeWhenSubmissionsAreNotStored(): void
    {
        $this->assertNotEmpty($this->errorsFor(['survey_mode' => 'once', 'store_submissions' => false]));
        $this->assertNotEmpty($this->errorsFor(['survey_mode' => 'edit', 'store_submissions' => false]));
        $this->assertSame([], $this->errorsFor(['survey_mode' => 'once']));
        $this->assertSame([], $this->errorsFor(['survey_mode' => 'off', 'store_submissions' => false]));
        $this->assertNotEmpty($this->errorsFor(['survey_mode' => 'bogus']));
    }

    public function testItAcceptsTheCustomerRepliesModesAndRejectsOthers(): void
    {
        foreach (['default', 'on', 'off'] as $mode) {
            $this->assertSame($mode, $this->normalizer()->normalize(['customer_replies' => $mode], $this->schema())['customer_replies']);
        }
        $this->assertNotEmpty($this->errorsFor(['customer_replies' => 'yes']));
        $this->assertNotEmpty($this->errorsFor(['customer_replies' => true]));
    }

    public function testItRejectsGuestDedupeWithoutAnEmailField(): void
    {
        $this->assertNotEmpty($this->errorsFor(['guest_dedupe' => true]));
        $this->assertSame([], $this->errorsFor(['guest_dedupe' => true, 'email_field' => 'mail']));
        $this->assertSame([], $this->errorsFor(['guest_dedupe' => true, 'autoresponder_email_field' => 'mail']));
        $this->assertNotEmpty($this->errorsFor(['guest_dedupe' => true, 'email_field' => 'name']));
    }

    private function condition(string $field = 'name', string $value = 'Sales'): array
    {
        return ['match' => 'all', 'rules' => [['field' => $field, 'operator' => 'equals', 'value' => $value]]];
    }

    public function testItPrunesStoredRoutingThatNamesAFieldThatIsGoneOrNotRoutable(): void
    {
        $emails = ['s@example.com'];
        $nested = ['match' => 'any', 'rules' => [
            ['field' => 'ghost', 'operator' => 'equals', 'value' => 'x'],
            ['match' => 'all', 'rules' => [['field' => 'name', 'operator' => 'equals', 'value_field' => 'ghost']]],
            ['field' => 'name', 'operator' => 'equals', 'value' => 'ok'],
        ]];
        $settings = [
            'submit_label' => 'Go',
            'notify_routes' => [
                ['condition' => $this->condition('name'), 'emails' => $emails, 'mode' => 'add'],
                ['condition' => $this->condition('ghost'), 'emails' => $emails, 'mode' => 'add'],
                ['condition' => $nested, 'emails' => $emails, 'mode' => 'replace'],
            ],
            'notify_when' => $this->condition('ghost'),
        ];

        $pruned = $this->normalizer()->pruneRouting($settings, $this->schema());

        $this->assertSame('Go', $pruned['submit_label']);
        $this->assertCount(2, $pruned['notify_routes']);
        $this->assertSame($this->condition('name'), $pruned['notify_routes'][0]['condition']);
        $this->assertSame(
            ['match' => 'any', 'rules' => [['field' => 'name', 'operator' => 'equals', 'value' => 'ok']]],
            $pruned['notify_routes'][1]['condition']
        );
        $this->assertSame('replace', $pruned['notify_routes'][1]['mode']);
        $this->assertNull($pruned['notify_when']);
        $this->assertSame([], $this->errorsFor($pruned));
    }

    public function testItLeavesSettingsWithoutRoutingKeysAloneWhenPruning(): void
    {
        $this->assertSame(['submit_label' => 'Go'], $this->normalizer()->pruneRouting(['submit_label' => 'Go'], $this->schema()));
    }

    public function testItRejectsRoutingRulesThatNameAnUnknownField(): void
    {
        $route = ['condition' => $this->condition(), 'emails' => ['s@example.com'], 'mode' => 'replace'];
        $ok = $this->normalizer()->normalize(['notify_routes' => [$route + ['junk' => 1]]], $this->schema());
        $this->assertSame([$route], $ok['notify_routes']);

        $this->assertNotEmpty($this->errorsFor(['notify_routes' => [array_merge($route, ['condition' => $this->condition('ghost')])]]));
        $this->assertNotEmpty($this->errorsFor(['notify_routes' => [array_merge($route, ['condition' => null])]]));
        $old = ['field' => 'name', 'value' => 'a', 'emails' => ['s@example.com'], 'mode' => 'add'];
        $this->assertNotEmpty($this->errorsFor(['notify_routes' => [$old]]));
        $this->assertNotEmpty($this->errorsFor(['notify_routes' => [array_merge($route, ['emails' => ['bad']])]]));
        $this->assertNotEmpty($this->errorsFor(['notify_routes' => [array_merge($route, ['mode' => 'other'])]]));
        $this->assertNotEmpty($this->errorsFor(['notify_routes' => array_fill(0, 21, $route)]));
        $this->assertNotEmpty($this->errorsFor(['notify_routes' => 'nope']));
    }

    public function testItRejectsARouteConditionOnAFieldThatCannotBeRouted(): void
    {
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'address', 'name' => 'addr'],
            ['kind' => 'field', 'type' => 'text', 'name' => 'plain'],
        ]]]];
        $routes = static fn (string $field, string $key = 'field'): array => [[
            'condition' => ['match' => 'all', 'rules' => [
                ['field' => 'plain', 'operator' => 'equals', 'value' => 'a'],
                ['match' => 'any', 'rules' => [$key === 'field'
                    ? ['field' => $field, 'operator' => 'not_empty']
                    : ['field' => 'plain', 'operator' => 'equals', 'value_field' => $field]]],
            ]],
            'emails' => ['s@example.com'],
            'mode' => 'add',
        ]];

        foreach ([['addr', 'field'], ['addr', 'value_field']] as [$field, $key]) {
            try {
                $this->normalizer()->normalize(['notify_routes' => $routes($field, $key)], $schema);
                $this->fail('a route on ' . $key . ' must be refused');
            } catch (InvalidSchemaException $e) {
                $this->assertNotEmpty($e->getErrors());
            }
        }
        $kept = $this->normalizer()->normalize(['notify_routes' => $routes('plain')], $schema)['notify_routes'];
        $this->assertCount(1, $kept);
    }

    public function testItNormalisesSendWhenConditionsWithTheSameRules(): void
    {
        $when = $this->condition('mail', 'x@y.test');
        $ok = $this->normalizer()->normalize(['notify_when' => $when, 'autoresponder_when' => $when + ['junk' => 1]], $this->schema());
        $this->assertSame($when, $ok['notify_when']);
        $this->assertSame($when, $ok['autoresponder_when']);

        $empty = $this->normalizer()->normalize(['notify_when' => ['match' => 'all', 'rules' => []], 'autoresponder_when' => 'x'], $this->schema());
        $this->assertNull($empty['notify_when']);
        $this->assertNull($empty['autoresponder_when']);

        $this->assertNotEmpty($this->errorsFor(['notify_when' => $this->condition('ghost')]));
        $this->assertNotEmpty($this->errorsFor(['autoresponder_when' => $this->condition('ghost')]));
        $this->assertNotEmpty($this->errorsFor(['notify_when' => ['rules' => [['field' => 'name', 'operator' => 'bogus', 'value' => 'a']]]]));
    }

    public function testItRefusesNotificationRoutingOnATypeWhoseCanBeRoutedReturnsFalseOrThatIsAFile(): void
    {
        $sealed = new class extends AbstractFieldType implements RoutableTypeInterface {
            public function getCode(): string
            {
                return 'sealed';
            }

            public function getLabel(): string
            {
                return 'Sealed';
            }

            public function canBeRouted(): bool
            {
                return false;
            }
        };
        $pool = new Pool(FieldPools::withFiles()->all() + ['sealed' => $sealed]);
        $route = ['emails' => ['s@example.com'], 'mode' => 'add'];
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'sealed', 'name' => 'locked'],
            ['kind' => 'field', 'type' => FieldPools::CUSTOM_FILE, 'name' => 'upload'],
            ['kind' => 'field', 'type' => 'text', 'name' => 'plain'],
        ]]]];
        $routed = fn (SettingsNormalizer $n, string $field): array => $n->normalize(
            ['notify_routes' => [['condition' => $this->condition($field)] + $route]],
            $schema
        )['notify_routes'];

        $normalizer = $this->normalizer(true, $pool);
        $this->assertCount(1, $routed($normalizer, 'plain'));
        foreach (['locked', 'upload'] as $name) {
            try {
                $routed($normalizer, $name);
                $this->fail($name . ' must not be routable');
            } catch (InvalidSchemaException $e) {
                $this->assertNotEmpty($e->getErrors());
            }
        }
    }

    public function testItAcceptsAThirdPartyEmailAddressTypeAsTheEmailField(): void
    {
        $pool = new Pool([WorkEmailType::CODE => new WorkEmailType()] + FieldPools::builtIn()->all());
        $schema = ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => WorkEmailType::CODE, 'name' => 'work'],
        ]]]];

        $out = $this->normalizer(true, $pool)->normalize(
            ['autoresponder_enabled' => true, 'autoresponder_email_field' => 'work', 'email_field' => 'work', 'survey_mode' => 'once', 'guest_dedupe' => true],
            $schema
        );

        $this->assertSame('work', $out['autoresponder_email_field']);
        $this->assertSame('work', $out['email_field']);
    }
}
