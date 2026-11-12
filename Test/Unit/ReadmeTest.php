<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use Furan\FormBuilder\Model\Http\PublicAddress;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Webhook\EndpointInput;
use Furan\FormBuilder\Model\Webhook\PayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Keeps README.md and docs/*.md in step with the code they describe.
 */
class ReadmeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Pool class (under Furan\FormBuilder\Model) => [di argument, interface short name]. */
    private const POOLS = [
        'FieldType\Pool' => ['types', 'FieldTypeInterface'],
        'Validation\RulePool' => ['rules', 'ValidationRuleInterface'],
        'Condition\OperatorPool' => ['operators', 'ConditionOperatorInterface'],
        'Condition\ActionPool' => ['actions', 'ConditionActionInterface'],
        'Prefill\Sources' => ['sources', 'PrefillSourceInterface'],
        'Spam\SpamGuard' => ['checks', 'SpamCheckInterface'],
        'Action\SubmissionActionPool' => ['actions', 'SubmissionActionInterface'],
        'Settings\ContributorPool' => ['contributors', 'FormSettingsContributorInterface'],
        'Schema\SchemaMigrator' => ['migrations', 'SchemaMigrationInterface'],
    ];

    /** Pools with no item in this module's di.xml. */
    private const EMPTY_BY_DEFAULT = ['Settings\\ContributorPool'];

    /** Arguments extensions fill that carry no di.xml item in this module. */
    private const EXTRA_ARGUMENTS = [
        'Block\Form' => ['scripts', 'styles'],
        'Model\FieldType\Hidden' => ['sources'],
        'Model\Validation\BuiltInRules' => ['rules'],
    ];

    /** Dispatched event => payload keys the README row must name. */
    private const EVENTS = [
        'furan_formbuilder_submission_validate_after' => ['form', 'values', 'errors'],
        'furan_formbuilder_submission_after' => ['form', 'submission', 'submission_id', 'values'],
        'furan_formbuilder_submission_updated' => ['form', 'submission', 'values'],
        'furan_formbuilder_submission_status_changed' => [
            'submission_id', 'form_id', 'old_status', 'new_status', 'source',
        ],
        'furan_formbuilder_thread_message_added' => ['submission_id', 'form_id', 'author', 'message_id'],
        'furan_formbuilder_storefront_config_build' => ['form', 'store_id', 'config'],
        'furan_formbuilder_webhook_send_before' => ['endpoint', 'event', 'delivery_id', 'envelope', 'veto'],
    ];

    public function testItDocumentsEveryDiPoolWithItsArgumentAndInterface(): void
    {
        $extending = $this->extending();
        $di = simplexml_load_string((string)file_get_contents(self::ROOT . '/etc/di.xml'));
        $this->assertInstanceOf(\SimpleXMLElement::class, $di);

        foreach (self::POOLS as $class => [$argument, $interface]) {
            $fullClass = 'Furan\\FormBuilder\\Model\\' . $class;
            $declared = $di->xpath(sprintf('//type[@name="%s"]/arguments/argument[@name="%s"]', $fullClass, $argument));
            if (!in_array($class, self::EMPTY_BY_DEFAULT, true)) {
                $this->assertNotEmpty($declared, $fullClass . ' no longer declares ' . $argument . ' in di.xml');
            }
            $row = $this->tableRow($extending, 'Model\\' . $class);
            $this->assertStringContainsString('`' . $argument . '`', $row, $class);
            $this->assertStringContainsString($interface, $row, $class);
        }

        foreach (self::EXTRA_ARGUMENTS as $class => $arguments) {
            foreach ($arguments as $argument) {
                $this->assertMatchesRegularExpression(
                    '/`' . preg_quote($class, '/') . '`[^\n]*`' . $argument . '`/',
                    $extending,
                    $class . ' ' . $argument
                );
            }
        }
        $this->assertMatchesRegularExpression('/' . preg_quote('`Model\FieldType\Hidden`', '/') . "[^\n]*'label' =>/", $extending);
        $this->assertStringContainsString('BuilderPhrases', $extending);
        $this->assertStringContainsString('`phrases`', $extending);

        foreach ($this->apiInterfaces() as $interface) {
            $this->assertStringContainsString($interface, $extending, $interface . ' is not documented');
        }
    }

    public function testItDocumentsEveryEventWithItsPayloadAndWhenItFires(): void
    {
        $extending = $this->extending();
        $this->assertEqualsCanonicalizing(array_keys(self::EVENTS), $this->dispatchedEvents());

        foreach (self::EVENTS as $event => $keys) {
            $row = $this->tableRow($extending, $event);
            $cells = array_map('trim', explode('|', trim($row, " |\n")));
            $this->assertCount(3, $cells, $event . ' needs name, payload and when columns');
            $this->assertNotSame('', $cells[2], $event . ' has no "when"');
            foreach ($keys as $key) {
                $this->assertStringContainsString('`' . $key . '`', $cells[1], $event . ' payload ' . $key);
            }
        }

        $this->assertStringContainsString('furan_formbuilder_form_*', $extending);
        $this->assertStringContainsString('furan_formbuilder_submission_*', $extending);
        $this->assertMatchesRegularExpression('/batch[^\n]*(do not|don\'t|never) fire/i', $extending);
        $this->assertStringContainsString('BatchDeleter', $extending);
        $this->assertStringContainsString('storefront_config_build', $extending);
        $this->assertMatchesRegularExpression('/never per-visitor|not per-visitor|no per-visitor/i', $extending);
    }

    public function testItDocumentsEveryEventAnyStorefrontScriptEmits(): void
    {
        $extending = $this->extending();

        $scripts = '';
        foreach (glob(self::ROOT . '/view/frontend/web/js/ffb-*.js') ?: [] as $script) {
            $scripts .= (string)file_get_contents($script);
        }
        preg_match_all("/emit\('(\w+)'/", $scripts, $events);
        $this->assertNotEmpty($events[1]);
        foreach (array_unique($events[1]) as $event) {
            $this->assertStringContainsString('`ffb:' . $event . '`', $extending, 'ffb:' . $event);
        }
    }

    public function testItDocumentsTheStorefrontAndBuilderJsRegistriesAndDomEvents(): void
    {
        $extending = $this->extending();

        $core = (string)file_get_contents(self::ROOT . '/view/frontend/web/js/ffb-core.js');
        preg_match_all('/registry\.(\w+) = function/', $core, $methods);
        $this->assertContains('fieldType', $methods[1]);
        foreach ($methods[1] as $method) {
            $this->assertStringContainsString('registry.' . $method . '(', $extending, 'registry.' . $method);
        }

        $builder = (string)file_get_contents(self::ROOT . '/view/adminhtml/web/js/builder-registry.js');
        preg_match_all('/^\s*api\.(preview|inspectorControl) = /m', $builder, $hooks);
        $this->assertCount(2, $hooks[1]);
        foreach ($hooks[1] as $hook) {
            $this->assertStringContainsString('builderRegistry.' . $hook . '(', $extending, $hook);
        }

        foreach (['read(wrapper)', 'write(wrapper, value)', 'summary(field, wrapper, value, optionLabel)', 'normalize(', 'intrinsicDefault(', 'hiddenSource', 'queue'] as $needle) {
            $this->assertStringContainsString($needle, $extending, $needle);
        }
        $this->assertStringContainsString('furanform_form_edit', $extending);
    }

    public function testItWalksThroughACompleteCustomFieldType(): void
    {
        $extending = $this->extending();
        foreach (
            [
                'FieldTypeInterface', 'ExportableTypeInterface', 'DisplayableTypeInterface', 'ClientMessagesInterface',
                'StatsAwareTypeInterface', 'getTemplate', 'registry.fieldType', 'builderRegistry.preview',
                'csp_whitelist.xml', 'etc/di.xml', 'i18n', '`scripts`',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $extending, $needle);
        }

        $blocks = $this->blocks($extending);
        $php = $this->blockContaining($blocks, 'php', 'class Satisfaction');
        $this->assertStringStartsWith('<?php', $php);
        $this->assertNotEmpty(token_get_all($php, TOKEN_PARSE));
        $registered = array_filter(
            $blocks,
            static fn (array $b): bool => $b[0] === 'xml' && str_contains($b[1], '<arguments>') && str_contains($b[1], 'Satisfaction')
        );
        $this->assertNotEmpty($registered, 'No di.xml block registering the Satisfaction type');
        foreach ($blocks as [$lang, $code]) {
            if ($lang === 'xml') {
                // Fragments use the xsi prefix without declaring it.
                $wrapped = '<root xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . $code . '</root>';
                $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($wrapped), $code);
            }
        }
        $template = $this->blockContaining($blocks, 'php', 'item/_open.phtml');
        $this->assertNotEmpty(token_get_all($template, TOKEN_PARSE));

        // The README example is this repository's own documentation; loading it proves it runs.
        $file = tempnam(sys_get_temp_dir(), 'ffb-readme-');
        file_put_contents($file, $php);
        require $file;
        unlink($file);
        $class = 'Vendor\\FormRating\\Model\\FieldType\\Satisfaction';
        $type = (new \ReflectionClass($class))->newInstance();
        $this->assertInstanceOf(\Furan\FormBuilder\Api\FieldTypeInterface::class, $type);
        $this->assertInstanceOf(\Furan\FormBuilder\Api\FieldType\ExportableTypeInterface::class, $type);
        $this->assertInstanceOf(\Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface::class, $type);
        $this->assertInstanceOf(\Furan\FormBuilder\Api\FieldType\ClientMessagesInterface::class, $type);
        $this->assertInstanceOf(\Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface::class, $type);

        $field = ['name' => 'rate', 'label' => 'Rate us', 'type' => 'satisfaction', 'settings' => ['max' => 5]];
        $value = $type->normalizeValue(['score' => ' 4 ', 'comment' => "Good\x00"], $field);
        $this->assertSame(['score' => '4', 'comment' => 'Good'], $value);
        $this->assertNull($type->normalizeValue(['score' => '', 'comment' => ''], $field));
        $this->assertNull($type->normalizeValue('4', $field));
        $this->assertNull($type->validateValue($value, $field, []));
        $this->assertNotNull($type->validateValue(['score' => '9', 'comment' => ''], $field, []));
        $this->assertSame(['Rate us (score)', 'Rate us (comment)'], $type->csvHeaders($field));
        $this->assertSame(['4', 'Good'], $type->csvCells($field, $value));
        $this->assertCount(count($type->csvHeaders($field)), $type->csvCells($field, null));
        $this->assertStringNotContainsString('<script', $type->toHtml($field, ['score' => '1', 'comment' => '<script>'], new \Magento\Framework\Escaper(), \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame('4/5: Good', $type->toText($field, $value, \Furan\FormBuilder\Api\Data\DisplayContext::forStore(1, 'en_GB')));
        $this->assertSame(
            \Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface::KIND_NONE,
            $type->statsKind($field)
        );
    }

    public function testItDocumentsTheFormSchemaVersion1AndMigrations(): void
    {
        $extending = $this->extending();
        $json = $this->blockContaining($this->blocks($extending), 'json', '"pages"');
        $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(Normalizer::SCHEMA_VERSION, $schema['version']);
        $this->assertSame(1, Normalizer::SCHEMA_VERSION);

        foreach (
            [
                'SchemaMigrationInterface', 'SchemaMigrator', '`migrations`', 'fromVersion', 'UnsupportedSchemaVersionException',
                'upgradeJson', 'read-only', 'page and item ids', 'SCHEMA_VERSION',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $extending, $needle);
        }
        foreach (['"kind": "field"', '"kind": "section"', '"kind": "content"', '"condition"', '"rules"'] as $needle) {
            $this->assertStringContainsString($needle, $json, $needle);
        }
    }

    public function testItDocumentsEveryNewPoolInterfaceAndJsRegistryMethodInTheExtendingSection(): void
    {
        $extending = $this->extending();
        foreach (
            [
                'ConditionActionInterface', 'registry.conditionAction(', 'hideOptions(wrapper, values)', 'AvailabilityInterface',
                'RelativeDateSourceInterface', 'AdminViewTypeInterface', 'RenderHintsInterface', 'StatsLabelInterface',
                'the `newsletter` action', '`skip`', '{{reference}}', '`notBefore`', '425',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $extending, $needle);
        }
        $this->assertMatchesRegularExpression('/`ffb:success`\s*\|\s*`reference`/', $extending);
        $this->assertStringContainsString('ConditionActionInterface', $this->tableRow($extending, 'Model\\Condition\\ActionPool'));
    }

    public function testItDocumentsHowAnExtensionBuildsTheFormWriteContext(): void
    {
        $extending = $this->extending();
        foreach (['FormManagementInterface', 'FormWriteContext::forAdmin(', 'FormWriteContext::allowAll()', 'CLI'] as $needle) {
            $this->assertStringContainsString($needle, $extending, $needle);
        }
    }

    public function testItListsTheNewCronJobInTheReadme(): void
    {
        $crontab = (string)file_get_contents(self::ROOT . '/etc/crontab.xml');
        $this->assertStringContainsString('furan_formbuilder_form_schedule', $crontab);
        $this->assertSame(1, preg_match('/Magento cron must run:[^\n]*/', $this->doc('operations'), $match));
        $this->assertStringContainsString('open or close time passes', $match[0]);
        $this->assertStringContainsString('furan_formbuilder_form_schedule', $match[0]);
    }

    public function testItListsEachFeatureInTheReadmeAndLinksEveryDocsPage(): void
    {
        $this->assertSame(1, preg_match('/^## Features\n(.*?)(?=^## )/ms', $this->readme(), $match));
        $titles = [
            'Conditional logic', 'Newsletter', 'Field groups', 'Input masks', 'Schedule', 'Reference', 'Webhooks',
        ];
        foreach ($titles as $title) {
            $this->assertMatchesRegularExpression('/^- \*\*' . preg_quote($title, '/') . '\*\*: \S/m', $match[1], $title);
        }
        foreach (glob(self::ROOT . '/docs/*.md') ?: [] as $page) {
            $this->assertStringContainsString('(docs/' . basename($page) . ')', $this->readme(), basename($page));
        }
    }

    public function testTheDocsUseNoDashPunctuation(): void
    {
        foreach (array_merge([self::ROOT . '/README.md'], glob(self::ROOT . '/docs/*.md') ?: []) as $page) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', (string)file_get_contents($page), $page);
        }
    }

    public function testItDocumentsTheWebhookEventsAndPayload(): void
    {
        $webhooks = $this->webhooks();
        foreach (EndpointInput::EVENTS as $event) {
            $this->assertStringContainsString('`' . $event . '`', $webhooks, $event);
        }
        $this->assertStringContainsString('`webhook.test`', $webhooks);

        $envelope = json_decode(
            $this->blockContaining($this->blocks($webhooks), 'json', '"submission.created"'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(['id', 'type', 'timestamp', 'version', 'data'], array_keys($envelope));
        $this->assertSame(PayloadBuilder::VERSION, $envelope['version']);
        $this->assertSame(
            ['submission', 'form', 'fields', 'values', 'files', 'product'],
            array_keys($envelope['data'])
        );
        $this->assertMatchesRegularExpression('/^msg_[0-9a-f]{32}$/', $envelope['id']);

        foreach (
            [
                'webhooks_manage', 'webhooks_personal', 'webhooks_replay', 'submissions_export', 'Redeliver', 'Resend',
                'furan_formbuilder.webhook', 'furan_formbuilder_realtime', 'furanform:webhooks:reencrypt',
                'webhook-id', 'at least once', 'Delivered', 'Failed', 'Blocked', 'Cancelled', 'Pending',
                'IMDSv2', 'hop limit', 'smokescreen', 'ClientHello', 'cannot recall',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase($needle, $webhooks, $needle);
        }
        $this->assertStringContainsString(
            'furanform:webhooks:reencrypt',
            $this->privacySection(),
            'The crypt key note in Privacy must name the webhook command'
        );
    }

    public function testItDocumentsTheEnvPhpSwitches(): void
    {
        $webhooks = $this->webhooks();
        $policy = (string)file_get_contents(self::ROOT . '/Model/Webhook/UrlPolicy.php');
        $keys = [
            'enabled', 'allow_http', 'ports', 'host_allowlist', 'ip_literal_hosts', 'nat64_decode', 'allow_cidrs',
            'connect_timeout_ms', 'timeout_ms',
        ];
        $example = $this->blockContaining($this->blocks($webhooks), 'php', "'furan_formbuilder' =>");
        $this->assertNotEmpty(token_get_all('<?php return [' . $example . '];', TOKEN_PARSE));
        foreach ($keys as $key) {
            $this->assertStringContainsString("'" . $key . "'", $policy, $key . ' is no longer read');
            $this->assertMatchesRegularExpression('/^\| `' . $key . '` \|[^\n]*\|[^\n]*\|/m', $webhooks, $key . ' has no table row');
            $this->assertStringContainsString("'" . $key . "'", $example, $key . ' missing from the env.php example');
        }
        $this->assertStringContainsString("'map'", $example);
        $this->assertStringContainsString("'ports'", $example);
        $this->assertStringContainsString('furan_formbuilder/map/ports', $webhooks);
        $this->assertStringContainsString('furan_formbuilder/webhooks/retention_days', $webhooks);
        $this->assertMatchesRegularExpression('/development only/i', $webhooks);
        $this->assertMatchesRegularExpression('/production mode[^\n]*ignored|ignored in production mode/i', $webhooks);
    }

    public function testItDocumentsHowWebhooksDecideWhichFieldsArePersonal(): void
    {
        $privacy = $this->webhooks();
        foreach (
            [
                'Contains personal data', 'personal by default', 'autocomplete', 'under the old type',
                'webhooks_personal', 'Plain text fields', 'tick them', 'follow the tick alone',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase($needle, $privacy, $needle);
        }
    }

    public function testItDocumentsThatDevFlagsNeedDeveloperMode(): void
    {
        $webhooks = $this->webhooks();
        $this->assertMatchesRegularExpression('/`allow_http` and `allow_cidrs` [^\n]*only in developer mode/i', $webhooks);
        $this->assertStringContainsString('furan_formbuilder_webhook_retention', $webhooks);
        $this->assertStringContainsString('at most 50 active endpoints', strtolower($webhooks));
        $this->assertStringContainsString('not revived', $webhooks);
    }

    public function testItIncludesReceiverVerificationExamples(): void
    {
        $webhooks = $this->webhooks();
        $blocks = $this->blocks($webhooks);

        $php = $this->blockContaining($blocks, 'php', 'function verifyWebhook');
        $this->assertNotEmpty(token_get_all('<?php ' . $php, TOKEN_PARSE));
        $this->assertStringContainsString('hash_equals', $php);
        $this->assertStringContainsString('300', $php);
        $file = tempnam(sys_get_temp_dir(), 'ffb-readme-');
        file_put_contents($file, '<?php ' . $php);
        require $file;
        unlink($file);

        // Standard Webhooks published vector.
        $secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
        $body = '{"test": 2432232314}';
        $headers = [
            'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
            'webhook-timestamp' => '1614265330',
            'webhook-signature' => 'v1,AAAA v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
        ];
        $this->assertTrue(\verifyWebhook($secret, $headers, $body, 1614265330 + 299));
        $this->assertFalse(\verifyWebhook($secret, $headers, $body, 1614265330 + 301));
        $this->assertFalse(\verifyWebhook($secret, $headers, $body . ' ', 1614265330));
        $this->assertFalse(\verifyWebhook($secret, ['webhook-signature' => 'v1,AAAA'] + $headers, $body, 1614265330));

        $node = $this->blockContaining($blocks, 'js', 'timingSafeEqual');
        foreach (['createHmac', 'sha256', 'webhook-id', 'webhook-timestamp', '300', 'split(\' \')', 'raw'] as $needle) {
            $this->assertStringContainsString($needle, $node, $needle);
        }
        $this->assertMatchesRegularExpression('/raw body/i', $webhooks);
        $this->assertMatchesRegularExpression('/constant-time/i', $webhooks);
    }

    public function testItPointsCrmConnectorsAtTheWebhookExtensionPoints(): void
    {
        $this->assertStringContainsString('Native CRM connectors are not included', $this->webhooks());
    }


    public function testItDocumentsTheDnsCacheWindowAndTheResolverTimeoutInTheReadmeOperatorsSection(): void
    {
        $this->assertSame(1, preg_match('/^## Operator notes\n(.*?)(?=^## |\z)/ms', $this->webhooks(), $match));
        $this->assertStringContainsString(PublicAddress::CACHE_TTL . ' seconds', $match[1]);
        $this->assertStringContainsString('options timeout:1 attempts:2', $match[1]);
    }

    public function testItDocumentsThatPersonalDataProtectionReliesOnTheAdminsFieldDeclaration(): void
    {
        $privacy = $this->privacySection();
        $this->assertStringContainsString('relies on the field declaration', $privacy);
        $this->assertStringContainsString('trusted roles', $privacy);
    }

    public function testItListsEveryStorefrontScriptInLoadOrder(): void
    {
        $phtml = (string)file_get_contents(self::ROOT . '/view/frontend/templates/form.phtml');
        $this->assertGreaterThan(0, preg_match_all('#Furan_FormBuilder::js/(ffb-[a-z]+\.js)#', $phtml, $found));
        $scripts = array_values(array_unique($found[1]));
        $this->assertSame('ffb-form.js', end($scripts));

        $this->assertSame(1, preg_match('/^## Storefront JavaScript\n(.*?)(?=^## |\z)/ms', $this->extending(), $match));
        $positions = array_map(static fn (string $script): int|false => strpos($match[1], '`' . $script . '`'), $scripts);
        $this->assertNotContains(false, $positions, 'a storefront script is missing from docs/extending.md');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    private function readme(): string
    {
        return (string)file_get_contents(self::ROOT . '/README.md');
    }

    private function doc(string $page): string
    {
        $file = self::ROOT . '/docs/' . $page . '.md';
        $this->assertFileExists($file);

        return (string)file_get_contents($file);
    }

    private function webhooks(): string
    {
        return $this->doc('webhooks');
    }

    private function privacySection(): string
    {
        $this->assertSame(1, preg_match('/^## Privacy\n(.*?)(?=^## |\z)/ms', $this->doc('submissions'), $match));

        return $match[1];
    }

    private function extending(): string
    {
        return $this->doc('extending');
    }

    private function tableRow(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, '|') && str_contains($line, '`' . $needle . '`')) {
                return $line;
            }
        }
        $this->fail('No table row for ' . $needle);
    }

    /**
     * @return string[]
     */
    private function dispatchedEvents(): array
    {
        $found = [];
        $dirs = ['Block', 'Controller', 'Model', 'Observer', 'Plugin', 'Console', 'Ui', 'Helper'];
        foreach ($dirs as $dir) {
            if (!is_dir(self::ROOT . '/' . $dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string)file_get_contents($file->getPathname());
                if (preg_match_all("/dispatch\(\s*'(furan_formbuilder_[a-z_]+)'/", $source, $matches)) {
                    $found = [...$found, ...$matches[1]];
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return string[] interface short names under Api/ that extensions implement or consume
     */
    private function apiInterfaces(): array
    {
        $names = [];
        foreach (array_merge(glob(self::ROOT . '/Api/*.php') ?: [], glob(self::ROOT . '/Api/FieldType/*.php') ?: []) as $file) {
            $name = basename($file, '.php');
            if (str_ends_with($name, 'Interface')) {
                $names[] = $name;
            }
        }
        $this->assertGreaterThan(20, count($names));

        return $names;
    }

    /**
     * @return array<int, array{0: string, 1: string}> fenced blocks as [language, code]
     */
    private function blocks(string $text): array
    {
        preg_match_all('/^```(\w+)\n(.*?)^```/ms', $text, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [$m[1], $m[2]], $matches);
    }

    private function blockContaining(array $blocks, string $language, string $needle): string
    {
        foreach ($blocks as [$lang, $code]) {
            if ($lang === $language && str_contains($code, $needle)) {
                return $code;
            }
        }
        $this->fail('No ' . $language . ' block containing ' . $needle);
    }
}
