<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block;

use DOMDocument;
use DOMXPath;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Directory\AddressRules;
use Furan\FormBuilder\Model\Directory\CountryOptions;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\PublicSchema;
use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Furan\FormBuilder\Model\Storefront\ConfigBuilder;
use Furan\FormBuilder\Model\Storefront\Eagerness;
use Furan\FormBuilder\Test\Unit\Block\Stub\TestableForm;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private LayoutInterface $layout;
    private LoggerInterface&Stub $logger;
    private Pool $pool;
    private ?CountryOptions $countryOptions = null;
    private ?array $recaptchaUi = null;
    private ?AddressRules $addressRules = null;
    /** @var array<string, mixed> Config getter => value for the map settings */
    private array $mapConfig = [];
    private bool $formsEnabled = true;
    private array $scripts = [];
    private array $styles = [];
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?LoggerInterface $logger = null): void
    {
        $this->layout = $this->createStub(LayoutInterface::class);
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $text = $this->createStub(FieldTypeInterface::class);
        $text->method('getTemplate')->willReturn('Furan_FormBuilder::item/text.phtml');
        $this->pool = new Pool(['text' => $text]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testItExposesCacheIdentitiesForTheForm(): void
    {
        $this->assertSame(['furan_form_12'], $this->block($this->form($this->schema()))->getIdentities());
        $this->assertSame([], $this->block(null)->getIdentities());
    }

    public function testItBuildsTheConfigWithSubmitTokenAndDraftSettings(): void
    {
        $schema = $this->schema();
        $config = $this->block($this->form($schema, ['draft_mode' => 'local', 'submit_label' => 'Go', 'next_label' => 'On', 'prev_label' => 'Off']))
            ->getConfig();

        $this->assertSame(12, $config['formId']);
        $this->assertSame('https://shop.test/furanform/form/submit', $config['submitUrl']);
        $this->assertSame('https://shop.test/furanform/form/token?form_id=12', $config['tokenUrl']);
        $this->assertSame('local', $config['draft']['mode']);
        $this->assertSame(24, $config['draft']['ttlHours']);
        $this->assertMatchesRegularExpression('/^ffb:1:12:[0-9a-f]{12}$/', $config['draft']['key']);
        $this->assertSame(['public' => true] + $schema, $config['schema']);
        $this->assertSame(['maxMb' => 5], $config['uploads']);
        foreach (['required', 'network', 'generic', 'restored', 'startOver', 'stepOf', 'fixErrors', 'sending'] as $key) {
            $this->assertNotSame('', $config['messages'][$key] ?? '', $key);
        }
        $this->assertSame('Step %1 of %2', $config['messages']['stepOf']);
        $this->assertSame('Sending…', $config['messages']['sending']);
        $this->assertSame(['submit' => 'Go', 'next' => 'On', 'prev' => 'Off'], $config['labels']);
    }

    public function testItReturnsAnEmptyConfigWhenNoFormIsSet(): void
    {
        $this->assertSame([], $this->block(null)->getConfig());
    }

    public function testItAddsReCaptchaConfigToTheFormConfigOnlyWhenEnabled(): void
    {
        $this->assertArrayNotHasKey('recaptcha', $this->block($this->form($this->schema()))->getConfig());

        $this->recaptchaUi = ['rendering' => ['sitekey' => 'k', 'size' => 'normal'], 'invisible' => false];
        $config = $this->block($this->form($this->schema()))->getConfig();

        $this->assertSame(
            [
                'scriptUrl' => 'https://static.test/Furan_FormBuilder/js/ffb-recaptcha.js',
                'rendering' => ['sitekey' => 'k', 'size' => 'normal'],
                'invisible' => false,
            ],
            $config['recaptcha']
        );
        $this->assertSame('Please confirm you are not a robot.', $config['messages']['recaptchaRequired']);
        $this->assertSame('Please wait a moment…', $config['messages']['waiting']);
    }

    public function testItOffersTheServerDraftEndpointOnlyForLocalDraftsWithSyncEnabled(): void
    {
        $this->mapConfig = ['isDraftSyncEnabled' => true];
        $local = $this->block($this->form($this->schema(), ['draft_mode' => 'local']))->getConfig();
        $session = $this->block($this->form($this->schema(), ['draft_mode' => 'session']))->getConfig();

        $this->assertSame('https://shop.test/furanform/form/draft?form_id=12', $local['draft']['serverUrl']);
        $this->assertArrayNotHasKey('serverUrl', $session['draft']);

        $this->mapConfig = ['isDraftSyncEnabled' => false];
        $disabled = $this->block($this->form($this->schema(), ['draft_mode' => 'local']))->getConfig();
        $this->assertArrayNotHasKey('serverUrl', $disabled['draft']);
    }

    public function testItBuildsTheSubmitTokenDraftAndLoginUrlsWithTheStoreMarker(): void
    {
        $this->mapConfig = ['isDraftSyncEnabled' => true];
        $block = $this->block($this->form($this->schema(), ['draft_mode' => 'local']));
        $block->getConfig();

        foreach (['furanform/form/submit', 'furanform/form/token', 'furanform/form/draft', 'furanform/account/login'] as $route) {
            $this->assertTrue($block->urlParams[$route]['_scope_to_url'] ?? false, $route);
            $this->assertArrayNotHasKey('_scope', $block->urlParams[$route], $route);
        }
    }

    public function testItChangesTheDraftKeyWhenADraftableFieldChanges(): void
    {
        $a = $this->block($this->form($this->schema()))->getConfig()['draft']['key'];
        $other = $this->schema();
        $other['pages'][0]['items'][] = ['id' => 'f_zzzz', 'kind' => 'field', 'type' => 'text', 'name' => 'extra'];
        $b = $this->block($this->form($other))->getConfig()['draft']['key'];
        $this->assertNotSame($a, $b);
    }

    public function testItEncodesTheConfigJsonSafelyForHtml(): void
    {
        $schema = $this->schema();
        $schema['pages'][0]['title'] = '</script><img src=x onerror=alert(1)> & \' "';
        $json = $this->block($this->form($schema))->getConfigJson();

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($schema['pages'][0]['title'], $decoded['schema']['pages'][0]['title']);
    }

    public function testItKeepsTheConfigInsideItsAttributeWhenAdminTextHasMarkupAndQuotes(): void
    {
        $title = '</script><img src=x onerror=alert(1)> & \' "';
        $schema = $this->schema();
        $schema['pages'][0]['title'] = $title;
        $html = $this->block($this->form($schema))->toHtml();

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR);
        $roots = (new \DOMXPath($dom))->query('//*[@data-ffb-root]');
        $this->assertSame(1, $roots->length);
        $this->assertSame(0, (new \DOMXPath($dom))->query('//img[@onerror]')->length);
        $config = json_decode($roots->item(0)->getAttribute('data-ffb-config'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($title, $config['schema']['pages'][0]['title']);
    }

    public function testItRendersNothingForAFormWithoutPages(): void
    {
        $this->assertSame('', $this->block(null)->toHtml());
        $this->assertSame('', $this->block($this->form(['version' => 1, 'pages' => []]))->toHtml());
    }

    public function testItRendersTheClosedMessageServerSideWhenClosedAtRenderTime(): void
    {
        $this->stubTemplate('Furan_FormBuilder::item/text.phtml', '<input data-stub="a">');
        $schema = $this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a'],
        ]]]);
        $closed = ['close_at' => '2020-01-01T00:00:00Z', 'closed_message' => 'We <b>closed</b>.'];
        $block = $this->block($this->form($schema, $closed));

        $xpath = $this->xpath($block->toHtml());

        $this->assertSame('We <b>closed</b>.', trim($xpath->query('//*[@data-ffb-closed]')->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//b')->length, 'The message is escaped');
        $this->assertSame(0, $xpath->query('//form')->length);
        $this->assertSame(0, $xpath->query('//*[@data-ffb-root]')->length);
        $this->assertSame(['furan_form_12'], $block->getIdentities());

        $notYet = $this->block($this->form($schema, ['open_from' => '2099-01-01T00:00:00Z', 'not_open_message' => 'Soon.']));
        $this->assertSame('Soon.', trim($this->xpath($notYet->toHtml())->query('//*[@data-ffb-closed]')->item(0)->textContent));

        $open = $this->block($this->form($schema, ['open_from' => '2020-01-01T00:00:00Z', 'close_at' => '2099-01-01T00:00:00Z']));
        $this->assertSame(1, $this->xpath($open->toHtml())->query('//form[@data-ffb-form]')->length);
    }

    public function testItLoadsTheTokenEagerlyForAScheduledForm(): void
    {
        $eager = fn (array $settings): bool => $this->block($this->form($this->schema(), $settings))->getConfig()['context']['eager'];

        $this->assertTrue($eager(['open_from' => '2099-01-01T00:00:00Z']));
        $this->assertTrue($eager(['close_at' => '2099-01-01T00:00:00Z']));
        $this->assertFalse($eager(['open_from' => '', 'close_at' => '']));
    }

    public function testItRendersNothingWhenFormsAreDisabledForTheStore(): void
    {
        $this->formsEnabled = false;
        $this->assertSame('', $this->block($this->form($this->schema()))->toHtml());
    }

    public function testALonePageTitledLikeThePageHeadingIsNotRepeated(): void
    {
        $single = $this->block($this->form($this->schema([['id' => 'p_aaaa', 'title' => 'Customer QA', 'items' => []]])));
        $this->assertFalse($single->repeatsPageHeading('Customer QA'), 'widgets set no page heading');
        $single->setData('page_heading', 'customer qa ');
        $this->assertTrue($single->repeatsPageHeading('Customer QA'));
        $this->assertFalse($single->repeatsPageHeading('About you'));

        $multi = $this->block($this->form($this->schema([
            ['id' => 'p_aaaa', 'title' => 'Customer QA', 'items' => []],
            ['id' => 'p_bbbb', 'title' => 'More', 'items' => []],
        ])));
        $multi->setData('page_heading', 'Customer QA');
        $this->assertFalse($multi->repeatsPageHeading('Customer QA'), 'step titles stay on multi-page forms');
    }

    public function testItRendersPagesSectionsAndProgressPerTheMarkupContract(): void
    {
        $this->stubTemplate('Furan_FormBuilder::item/text.phtml', '<input data-stub="<?= $escaper->escapeHtmlAttr($block->getData(\'item\')[\'name\']) ?>">');
        $schema = $this->schema([
            ['id' => 'p_aaaa', 'title' => 'One <b>', 'items' => [
                ['id' => 's_aaaa', 'kind' => 'section', 'title' => 'Sec', 'description' => 'Desc', 'items' => [
                    ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a'],
                ]],
            ]],
            ['id' => 'p_bbbb', 'title' => '', 'items' => []],
        ]);
        $xpath = $this->xpath($this->block($this->form($schema))->toHtml());

        $this->assertSame(1, $xpath->query('//div[@data-ffb-root][contains(@class,"ffb")]/form[@data-ffb-form]')->length);
        $this->assertSame(2, $xpath->query('//section[@class="ffb-page"][@data-ffb-page][@data-ffb-item]')->length);
        $this->assertSame('p_aaaa', $xpath->query('//section[@data-ffb-page]')->item(0)->getAttribute('data-ffb-item'));
        $this->assertSame('One <b>', $xpath->query('//section[1]/h2[@class="ffb-page-title"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//h2[@class="ffb-page-title"]')->length);
        $this->assertSame(0, $xpath->query('//b')->length);
        $this->assertSame(1, $xpath->query('//section[1]/div[@class="ffb-grid"]/fieldset[contains(@class,"ffb-section")][@data-ffb-item="s_aaaa"]/legend[@class="ffb-section-title"]')->length);
        $this->assertSame('Desc', $xpath->query('//fieldset//p[@class="ffb-section-description"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//fieldset/div[@class="ffb-grid"]/input[@data-stub="a"]')->length);
        $steps = $xpath->query('//ol[@data-ffb-progress]/li[@class="ffb-progress-step"]');
        $this->assertSame(2, $steps->length);
        $this->assertSame('p_aaaa', $steps->item(0)->getAttribute('data-ffb-step'));
        $this->assertSame('One <b>', $steps->item(0)->textContent);
        $this->assertSame('Step 2', $steps->item(1)->textContent);
        $this->assertSame(1, $xpath->query('//*[@data-ffb-prev]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-ffb-next]')->length);
        $this->assertSame('Submit', $xpath->query('//button[@type="submit"][@data-ffb-submit]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//div[@class="ffb-status"][@role="status"][@aria-live="polite"][@data-ffb-status]')->length);
        $this->assertSame(1, $xpath->query('//div[@class="ffb-success"][@data-ffb-success][@hidden][@tabindex="-1"]')->length);
        $this->assertSame(1, $xpath->query('//noscript/p[@class="ffb-noscript"]')->length);
        $this->assertSame(1, $xpath->query('//div[@data-ffb-root][@data-ffb-config]')->length);
        $this->assertSame(0, $xpath->query('//script[@type="application/json"]')->length);
    }

    public function testItOmitsTheProgressForSinglePagesAndWhenDisabled(): void
    {
        $this->assertSame(0, $this->xpath($this->block($this->form($this->schema()))->toHtml())->query('//ol')->length);
        $pages = [['id' => 'p_aaaa', 'title' => 'A', 'items' => []], ['id' => 'p_bbbb', 'title' => 'B', 'items' => []]];
        $html = $this->block($this->form($this->schema($pages), ['show_progress' => false]))->toHtml();
        $this->assertSame(0, $this->xpath($html)->query('//ol')->length);
    }

    public function testItRendersContentItemsWithItemWrappers(): void
    {
        $block = $this->block($this->form($this->schema()));
        $xpath = $this->xpath($block->renderItem(['id' => 'c_aaaa', 'kind' => 'content', 'type' => 'heading', 'text' => '<i>Hi</i>', 'level' => 9, 'width' => 'half']));
        $this->assertSame(1, $xpath->query('//div[contains(@class,"ffb-item")][contains(@class,"ffb-content-heading")][contains(@class,"ffb-w-half")][@data-ffb-item="c_aaaa"]/h4')->length);
        $this->assertSame('<i>Hi</i>', $xpath->query('//h4')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//i')->length);

        $xpath = $this->xpath($block->renderItem(['id' => 'c_aaab', 'kind' => 'content', 'type' => 'heading', 'text' => 'Low', 'level' => 0, 'width' => 'bad"x']));
        $this->assertSame(1, $xpath->query('//h2')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class,"ffb-w-full")]')->length);

        $xpath = $this->xpath($block->renderItem(['id' => 'c_aaac', 'kind' => 'content', 'type' => 'divider', 'width' => 'full']));
        $this->assertSame(1, $xpath->query('//div[@data-ffb-item="c_aaac"]/hr[@class="ffb-divider"]')->length);

        $xpath = $this->xpath($block->renderItem(['id' => 'c_aaad', 'kind' => 'content', 'type' => 'spacer', 'width' => 'full']));
        $this->assertSame(1, $xpath->query('//div[@data-ffb-item="c_aaad"]/div[@class="ffb-spacer"][@aria-hidden="true"]')->length);
    }

    public function testItRendersParagraphsAsEscapedTextWithLineBreaks(): void
    {
        $block = $this->block($this->form($this->schema()));
        $html = $block->renderItem([
            'id' => 'c_aaaa', 'kind' => 'content', 'type' => 'paragraph', 'width' => 'full',
            'text' => "One <script>alert(1)</script>\nsecond line\r\n\r\n\r\nTwo & <b>x</b>",
        ]);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $xpath = $this->xpath($html);
        $paragraphs = $xpath->query('//div[@data-ffb-item="c_aaaa"]/p');
        $this->assertSame(2, $paragraphs->length);
        $this->assertSame(1, $xpath->query('//p[1]/br')->length);
        $this->assertSame('One <script>alert(1)</script>second line', str_replace("\n", '', $paragraphs->item(0)->textContent));
        $this->assertSame('Two & <b>x</b>', $paragraphs->item(1)->textContent);
    }

    public function testItRendersRichTextNoticeAndSummaryItems(): void
    {
        $block = $this->block($this->form($this->schema()));

        $html = $block->renderItem([
            'id' => 'c_rich', 'kind' => 'content', 'type' => 'rich_text', 'width' => 'half',
            'text' => "Hi **there** <script>x</script> [a](javascript:alert(1)) [b](https://e.com)",
        ]);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:alert(1)">', $html);
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//div[@data-ffb-item="c_rich"][contains(@class,"ffb-w-half")]/div[@class="ffb-rich"]/p/strong')->length);
        $this->assertSame(1, $xpath->query('//div[@class="ffb-rich"]//a[@href="https://e.com"][@rel="noopener noreferrer"]')->length);

        $xpath = $this->xpath($block->renderItem([
            'id' => 'c_note', 'kind' => 'content', 'type' => 'notice', 'width' => 'full',
            'text' => "a <b>\nb", 'style' => 'warning', 'title' => 'T <i>',
        ]));
        $this->assertSame(1, $xpath->query('//div[@class="ffb-notice ffb-notice-warning"][@role="note"]')->length);
        $this->assertSame('T <i>', $xpath->query('//p[@class="ffb-notice-title"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//p[@class="ffb-notice-text"]/br')->length);
        $this->assertSame(0, $xpath->query('//b')->length);

        $xpath = $this->xpath($block->renderItem([
            'id' => 'c_sum', 'kind' => 'content', 'type' => 'summary', 'width' => 'full', 'title' => 'Review <x>',
        ]));
        $this->assertSame('Review <x>', $xpath->query('//div[@class="ffb-summary"][@data-ffb-summary]/h3[@class="ffb-summary-title"]')->item(0)->textContent);
        $this->assertSame('Edit', $xpath->query('//div[@data-ffb-summary]')->item(0)->getAttribute('data-ffb-edit-label'));
        $this->assertSame(1, $xpath->query('//div[@data-ffb-summary]/dl[@class="ffb-summary-list"][not(*)]')->length);
    }

    public function testItRendersTheHoneypotTokenAndFormKeyInputs(): void
    {
        $xpath = $this->xpath($this->block($this->form($this->schema()))->toHtml());

        $form = $xpath->query('//form[@data-ffb-form]')->item(0);
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
        $this->assertSame('https://shop.test/furanform/form/submit', $form->getAttribute('action'));
        $this->assertTrue($form->hasAttribute('novalidate'));
        $this->assertSame('12', $xpath->query('//input[@type="hidden"][@name="form_id"]')->item(0)->getAttribute('value'));
        $this->assertSame('', $xpath->query('//input[@type="hidden"][@name="form_key"]')->item(0)->getAttribute('value'));
        $this->assertSame('', $xpath->query('//input[@type="hidden"][@name="ffb_token"]')->item(0)->getAttribute('value'));
        $honeypot = $xpath->query('//div[@class="ffb-hp"][@aria-hidden="true"]//input[@name="ffb_website"]')->item(0);
        $this->assertNotNull($honeypot);
        $this->assertSame('-1', $honeypot->getAttribute('tabindex'));
        $this->assertSame('off', $honeypot->getAttribute('autocomplete'));
        $this->assertSame('', $honeypot->getAttribute('value'));
    }

    public function testItReadsTheFormOnlyThroughGetForm(): void
    {
        $form = $this->form($this->schema());
        $block = $this->block(null, true);
        $block->lazyForm = $form;

        $this->assertSame(['furan_form_12'], $block->getIdentities());
        $this->assertSame(12, $block->getConfig()['formId']);
        $this->assertStringContainsString('data-ffb-root', $block->toHtml());
    }

    public function testItLoadsTheStorefrontScriptsOncePerPageInTheRightOrder(): void
    {
        $first = $this->block($this->form($this->schema()));
        $second = $this->block($this->form($this->schema()));

        $this->assertStringContainsString('ffb-core.js', $first->toHtml());
        $this->assertTrue($this->xpath($second->toHtml())->query('//script[@src]')->length === 0);

        $this->layout = $this->createStub(LayoutInterface::class);
        $other = $this->block($this->form($this->schema()));
        $xpath = $this->xpath($other->toHtml());
        $this->assertSame(
            [
                'https://static.test/Furan_FormBuilder/js/ffb-core.js',
                'https://static.test/Furan_FormBuilder/js/ffb-types.js',
                'https://static.test/Furan_FormBuilder/js/ffb-drafts.js',
                'https://static.test/Furan_FormBuilder/js/ffb-submit.js',
                'https://static.test/Furan_FormBuilder/js/ffb-form.js',
            ],
            array_map(static fn ($n) => $n->getAttribute('src'), iterator_to_array($xpath->query('//script[@src][@defer]')))
        );
        $this->assertSame('https://static.test/Furan_FormBuilder/css/ffb.css', $xpath->query('//link[@rel="stylesheet"]')->item(0)->getAttribute('href'));
    }

    public function testItRendersConfiguredExtensionScriptsAfterTheBuiltInScriptsAndBeforeFfbForm(): void
    {
        $this->scripts = ['acme' => 'Acme_Extras::js/acme.js'];

        $xpath = $this->xpath($this->block($this->form($this->schema()))->toHtml());

        $this->assertSame(
            [
                'https://static.test/Furan_FormBuilder/js/ffb-core.js',
                'https://static.test/Furan_FormBuilder/js/ffb-types.js',
                'https://static.test/Furan_FormBuilder/js/ffb-drafts.js',
                'https://static.test/Furan_FormBuilder/js/ffb-submit.js',
                'https://static.test/Acme_Extras/js/acme.js',
                'https://static.test/Furan_FormBuilder/js/ffb-form.js',
            ],
            array_map(static fn ($n) => $n->getAttribute('src'), iterator_to_array($xpath->query('//script[@src][@defer]')))
        );
    }

    public function testItOrdersExtensionScriptsBySortOrder(): void
    {
        $this->scripts = [
            'late' => ['file' => 'Acme_Extras::js/late.js', 'sortOrder' => 20],
            'plain' => 'Acme_Extras::js/plain.js',
            'early' => ['file' => 'Acme_Extras::js/early.js', 'sortOrder' => -5],
            'tie' => ['file' => 'Acme_Extras::js/tie.js', 'sortOrder' => 0],
        ];

        $urls = $this->block($this->form($this->schema()))->getExtensionScripts();

        $this->assertSame(
            [
                'https://static.test/Acme_Extras/js/early.js',
                'https://static.test/Acme_Extras/js/plain.js',
                'https://static.test/Acme_Extras/js/tie.js',
                'https://static.test/Acme_Extras/js/late.js',
            ],
            $urls
        );
    }

    public function testItRejectsAnExtensionScriptThatIsAnAbsoluteUrl(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->scripts = [
            'abs' => 'https://evil.test/x.js',
            'proto' => '//evil.test/x.js',
            'up' => 'Acme_Extras::js/../../x.js',
            'junk' => ['file' => 42],
            'css' => 'Acme_Extras::css/x.css',
            'ok' => 'Acme_Extras::js/ok.js',
        ];
        $logger->expects($this->exactly(5))->method('warning');

        $this->assertSame(
            ['https://static.test/Acme_Extras/js/ok.js'],
            $this->block($this->form($this->schema()))->getExtensionScripts()
        );
    }

    public function testItRendersConfiguredExtensionStylesheetsAfterFfbCss(): void
    {
        $this->styles = ['acme' => 'Acme_Extras::css/acme.css', 'bad' => 'Acme_Extras::js/x.js'];

        $xpath = $this->xpath($this->block($this->form($this->schema()))->toHtml());

        $this->assertSame(
            ['https://static.test/Furan_FormBuilder/css/ffb.css', 'https://static.test/Acme_Extras/css/acme.css'],
            array_map(static fn ($n) => $n->getAttribute('href'), iterator_to_array($xpath->query('//link[@rel="stylesheet"]')))
        );
    }

    public function testItRendersEveryTemplateThroughTheRenderTemplateSeam(): void
    {
        $this->stubTemplate('Furan_FormBuilder::item/text.phtml', '<i></i>');
        $schema = $this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a'],
            ['id' => 'c_aaaa', 'kind' => 'content', 'type' => 'divider', 'width' => 'full'],
        ]]]);
        $block = $this->block($this->form($schema));
        $block->toHtml();

        $ids = array_column($block->rendered, 0);
        $this->assertSame(
            [
                'Furan_FormBuilder::form.phtml',
                'Furan_FormBuilder::item/page.phtml',
                'Furan_FormBuilder::item/text.phtml',
                'Furan_FormBuilder::item/divider.phtml',
            ],
            $ids
        );
        $varsOfField = $block->rendered[2][1];
        $this->assertSame($block, $varsOfField['parent_block']);
        $this->assertSame(12, $varsOfField['form_id']);
        $this->assertSame('a', $varsOfField['item']['name']);
    }

    public function testItReadsTheConfigFromTheRootDataAttribute(): void
    {
        $schema = $this->schema();
        $schema['pages'][0]['title'] = '"><img src=x onerror=alert(1)> & \'';
        $html = $this->block($this->form($schema))->toHtml();
        $root = $this->xpath($html)->query('//div[@data-ffb-root]')->item(0);

        $this->assertSame(12, json_decode($root->getAttribute('data-ffb-config'), true, 512, JSON_THROW_ON_ERROR)['formId']);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script type="application/json"', $html);
    }

    public function testItRendersTheTemplateSetOnTheBlock(): void
    {
        $this->stubTemplate('Vendor_Theme::custom.phtml', '<p class="custom"><?= (int)$block->getData(\'parent_block\')->getForm()->getId() ?></p>');
        $block = $this->block($this->form($this->schema()));
        $block->setTemplate('Vendor_Theme::custom.phtml');
        $block->stubs = $this->tmpTemplates;

        $this->assertSame('<p class="custom">12</p>', $block->toHtml());
        $this->assertSame('Vendor_Theme::custom.phtml', $block->rendered[0][0]);
    }

    public function testItGivesUniqueElementIdsWhenTheSameFormRendersTwice(): void
    {
        $schema = $this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a'],
        ]]]);
        $first = $this->block($this->form($schema));
        $second = $this->block($this->form($schema));

        $firstHtml = $first->toHtml();
        $secondHtml = $second->toHtml();

        $this->assertStringContainsString('id="ffb-12-a"', $firstHtml);
        $this->assertStringContainsString('id="ffb-12-2-a"', $secondHtml);
        $this->assertStringNotContainsString('id="ffb-12-a"', $secondHtml);
        $this->assertSame('session', $first->getConfig()['draft']['mode']);
        $this->assertSame('off', $second->getConfig()['draft']['mode']);
        $this->assertSame($first->getConfig()['draft']['key'], $second->getConfig()['draft']['key']);
    }

    public function testItUsesConsistentRequiredAndFixErrorsMessages(): void
    {
        $config = $this->block($this->form($this->schema()))->getConfig();

        $this->assertSame('This field is required.', $config['messages']['required']);
        $this->assertSame('Please fix the highlighted fields.', $config['messages']['fixErrors']);
    }

    public function testItTranslatesTheDefaultButtonLabelsWhenSettingsAreEmpty(): void
    {
        $config = $this->block($this->form($this->schema(), ['submit_label' => '', 'next_label' => ' ', 'prev_label' => '']))->getConfig();

        $this->assertSame(['submit' => 'Submit', 'next' => 'Next', 'prev' => 'Back'], $config['labels']);
    }

    public function testItMemoisesTheConfigPerBlockInstance(): void
    {
        $block = $this->block($this->form($this->schema()));

        $this->assertSame($block->getConfig(), $block->getConfig());
        $this->assertSame(1, $block->getInstanceNumber());
        $this->assertSame(1, $block->getInstanceNumber());
    }

    public function testItRendersNothingAndLogsForAnUnknownFieldType(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $logger->expects($this->once())->method('error');
        $block = $this->block($this->form($this->schema()));
        $this->assertSame('', $block->renderItem(['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'nope', 'name' => 'a']));
    }

    public function testItRendersTheRestOfTheFormWhenOneFieldTypeIsUnknown(): void
    {
        $this->stubTemplate('Furan_FormBuilder::item/text.phtml', '<input data-stub="<?= $escaper->escapeHtmlAttr($block->getData(\'item\')[\'name\']) ?>">');
        $schema = $this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a'],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'gone', 'name' => 'b', 'required' => true],
            ['id' => 'f_cccc', 'kind' => 'field', 'type' => 'text', 'name' => 'c'],
        ]]]);
        $xpath = $this->xpath($this->block($this->form($schema))->toHtml());

        $this->assertSame(['a', 'c'], array_map(
            static fn ($n) => $n->getAttribute('data-stub'),
            iterator_to_array($xpath->query('//input[@data-stub]'))
        ));
        $this->assertSame(1, $xpath->query('//button[@type="submit"][@data-ffb-submit]')->length);
    }

    public function testItRendersCountryOptionsFromTheCountryOptionsService(): void
    {
        $options = $this->createMock(CountryOptions::class);
        $options->expects($this->once())->method('forStore')->with(1)
            ->willReturn([['value' => 'GB', 'label' => 'United Kingdom']]);
        $this->countryOptions = $options;
        $block = $this->block($this->form($this->schema()));

        $this->assertSame([['value' => 'GB', 'label' => 'United Kingdom']], $block->getCountryOptions());
        $this->assertSame([['value' => 'GB', 'label' => 'United Kingdom']], $block->getCountryOptions());
    }

    private function schema(?array $pages = null): array
    {
        return ['version' => 1, 'pages' => $pages ?? [['id' => 'p_aaaa', 'title' => 'Page', 'items' => []]]];
    }

    public function testItAppliesTheStyleModeAndAValidatedAccentColour(): void
    {
        $root = fn (array $settings) => $this->xpath($this->block($this->form($this->schema(), $settings))->toHtml())
            ->query('//div[@data-ffb-root]')->item(0);

        $default = $root([]);
        $this->assertSame('ffb ffb-skin', $default->getAttribute('class'));
        $this->assertFalse($default->hasAttribute('style'));

        $this->assertSame('ffb', $root(['style' => 'theme'])->getAttribute('class'));
        $this->assertSame('--ffb-accent: #123456; --ffb-on-accent: #fff', $root(['accent_color' => '#123456'])->getAttribute('style'));
        $this->assertSame('--ffb-accent: #ffff00; --ffb-on-accent: #1f2328', $root(['accent_color' => '#ffff00'])->getAttribute('style'));
        foreach (['red', '#123456;color:red', '#ABCDEF', '"><script>'] as $bad) {
            $this->assertFalse($root(['accent_color' => $bad])->hasAttribute('style'), $bad);
        }
    }

    public function testItPassesTheStreetLineCountToTheAddressTemplate(): void
    {
        $this->useDirectory();
        $this->stubTemplate('Furan_FormBuilder::item/address.phtml', '<i></i>');
        $this->pool = new Pool([
            'address' => new \Furan\FormBuilder\Model\FieldType\Address(
                $this->addressRules,
                \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create()
            ),
        ]);
        $block = $this->block($this->form($this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'address', 'name' => 'addr'],
        ]]])));

        $block->toHtml();

        $this->assertSame(['street_lines' => 3], $block->rendered[2][1]['item']['render']);
    }

    public function testItLetsACustomTypeAddRenderHintsToItsItem(): void
    {
        $hinted = new class extends \Furan\FormBuilder\Model\FieldType\AbstractFieldType implements
            \Furan\FormBuilder\Api\FieldType\RenderHintsInterface
        {
            public function getCode(): string
            {
                return 'hinted';
            }

            public function getLabel(): string
            {
                return 'Hinted';
            }

            public function renderHints(array $field, int $storeId): array
            {
                return ['tone' => 'warm', 'name' => $field['name'], 'store' => $storeId];
            }
        };
        $this->pool = new Pool(['text' => $this->pool->get('text'), 'hinted' => $hinted]);
        $this->stubTemplate('Furan_FormBuilder::item/hinted.phtml', '<i></i>');
        $this->stubTemplate('Furan_FormBuilder::item/text.phtml', '<i></i>');
        $block = $this->block($this->form($this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'hinted', 'name' => 'one', 'render' => ['evil' => 1]],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'two', 'render' => ['evil' => 1]],
        ]]])));

        $block->toHtml();

        $items = [];
        foreach ($block->rendered as [$template, $vars]) {
            if (isset($vars['item']['name'])) {
                $items[$vars['item']['name']] = $vars['item'];
            }
        }
        $this->assertSame(['tone' => 'warm', 'name' => 'one', 'store' => 1], $items['one']['render']);
        $this->assertArrayNotHasKey('render', $items['two']);
    }

    public function testItMergesStorefrontConfigReturnedByFieldTypesIntoTheFormConfigForTheRenderingStore(): void
    {
        $gauge = $this->gaugeType(['gauge' => ['max' => 9]]);
        $this->pool = new Pool(['text' => $this->pool->get('text'), 'gauge' => $gauge]);
        $schema = $this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'gauge', 'name' => 'one'],
            ['id' => 's_aaaa', 'kind' => 'section', 'title' => 'S', 'items' => [
                ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'gauge', 'name' => 'two'],
            ]],
            ['id' => 'f_cccc', 'kind' => 'field', 'type' => 'text', 'name' => 'plain'],
        ]]]);

        $config = $this->block($this->form($schema))->getConfig();

        $this->assertSame(['max' => 9], $config['gauge']);
        $this->assertCount(1, $gauge->calls, 'Once per type, not per field');
        $this->assertSame([['one', 'two'], 1], [array_column($gauge->calls[0][0], 'name'), $gauge->calls[0][1]]);
        $this->assertArrayNotHasKey('gauge', $this->block($this->form($this->schema()))->getConfig());
    }

    public function testItNeverLetsATypesStorefrontConfigOverwriteAnExistingConfigKey(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $gauge = $this->gaugeType(['formId' => 999, 'recaptcha' => 'x', 'draft' => [], 'gauge' => 1]);
        $this->pool = new Pool(['gauge' => $gauge]);
        $logger->expects($this->atLeastOnce())->method('warning');
        $schema = $this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'gauge', 'name' => 'one'],
        ]]]);

        $config = $this->block($this->form($schema))->getConfig();

        $this->assertSame(12, $config['formId']);
        $this->assertNotSame([], $config['draft']);
        $this->assertArrayNotHasKey('recaptcha', $config);
        $this->assertSame(1, $config['gauge']);
    }

    /** @param array<string, mixed> $returns */
    private function gaugeType(array $returns): object
    {
        return new class ($returns) extends \Furan\FormBuilder\Model\FieldType\AbstractFieldType implements
            \Furan\FormBuilder\Api\FieldType\StorefrontConfigInterface
        {
            public array $calls = [];

            public function __construct(private readonly array $returns)
            {
            }

            public function getCode(): string
            {
                return 'gauge';
            }

            public function getLabel(): string
            {
                return 'Gauge';
            }

            public function storefrontConfig(array $fields, int $storeId): array
            {
                $this->calls[] = [$fields, $storeId];
                return $this->returns;
            }
        };
    }

    public function testItKeepsTheConfigOfAddressAndMapFieldsByteIdentical(): void
    {
        $this->mapConfig = [
            'getMapProvider' => 'osm', 'getTileUrl' => 'https://tiles.test/{z}/{x}/{y}.png',
            'getAttribution' => '&copy; Tiles', 'getMaxZoom' => 18, 'getGoogleApiKey' => '',
            'getGoogleMapId' => 'MAPID', 'isMapSearchEnabled' => true,
        ];
        $this->useDirectory();
        $schema = $this->schema([['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'plain'],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'map', 'name' => 'spot'],
            ['id' => 's_aaaa', 'kind' => 'section', 'title' => 'S', 'items' => [
                ['id' => 'f_cccc', 'kind' => 'field', 'type' => 'address', 'name' => 'addr'],
            ]],
        ]]]);
        $this->usePoolWithAddressAndMap();

        $config = $this->block($this->form($schema))->getConfig();

        $this->assertSame(self::ADDRESS_MAP_CONFIG, json_encode(
            array_intersect_key($config, array_flip(['directory', 'regionsUrl', 'maps']))
        ));
        $this->assertSame(['formId', 'submitUrl', 'tokenUrl', 'draft', 'schema', 'uploads', 'messages', 'showReference', 'context', 'labels', 'directory', 'regionsUrl', 'maps'], array_keys($config));
    }

    private const ADDRESS_MAP_CONFIG = '{"directory":{"allowedCountries":["GB"],"regionsRequired":[],"optionalZip":[],"postcodes":{"GB":["^[A-Z]"]},"streetLines":3},"regionsUrl":"https:\/\/shop.test\/furanform\/directory\/regions","maps":{"provider":"osm","tileUrl":"https:\/\/tiles.test\/{z}\/{x}\/{y}.png","attribution":"&copy; Tiles","maxZoom":18,"assets":{"leafletJs":"https:\/\/static.test\/Furan_FormBuilder\/vendor\/leaflet\/1.9.4\/leaflet.js","leafletCss":"https:\/\/static.test\/Furan_FormBuilder\/vendor\/leaflet\/1.9.4\/leaflet.css","imagePath":"https:\/\/static.test\/Furan_FormBuilder\/vendor\/leaflet\/1.9.4\/images\/","mapJs":"https:\/\/static.test\/Furan_FormBuilder\/js\/ffb-map.js","googleJs":"https:\/\/static.test\/Furan_FormBuilder\/js\/ffb-map-google.js"},"googleKey":"","mapId":"","canvasMarker":"3f86cffe8afeb6552c14ec9ee9198ca1","locale":"","searchUrl":"https:\/\/shop.test\/furanform\/map\/search","messages":{"pinSet":"Pin set at %1, %2","privacy":"Loading the map sends your IP address to tiles.test.","loadError":"The map could not be loaded. Please enter the coordinates instead."}}}';

    private function useDirectory(): void
    {
        $this->addressRules = $this->createStub(AddressRules::class);
        $this->addressRules->method('getDirectoryData')->willReturn([
            'allowedCountries' => ['GB'], 'regionsRequired' => [], 'optionalZip' => [],
            'postcodes' => ['GB' => ['^[A-Z]']], 'streetLines' => 3, 'regions' => ['GB' => ['x' => 'y']],
        ]);
    }

    private function usePoolWithAddressAndMap(): void
    {
        $urls = \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create();
        $this->pool = new Pool([
            'text' => $this->pool->get('text'),
            'address' => new \Furan\FormBuilder\Model\FieldType\Address($this->addressRules, $urls),
            'map' => new \Furan\FormBuilder\Model\FieldType\Map(
                $this->moduleConfig(),
                $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class),
                $urls,
                \Furan\FormBuilder\Test\Unit\Helper\MapMarkerFactory::create()
            ),
        ]);
    }

    public function testItRendersTheProductIdOnlyOnProductPages(): void
    {
        $this->fullActionName = 'catalog_product_view';
        $this->requestParams = ['id' => '42'];
        $this->assertSame(42, $this->block($this->form($this->schema()))->getConfig()['context']['productId']);

        $this->fullActionName = 'cms_page_view';
        $this->assertNull($this->block($this->form($this->schema()))->getConfig()['context']['productId']);

        $this->fullActionName = 'catalog_category_view';
        $this->requestParams = ['id' => '42', 'product' => '0'];
        $this->assertNull($this->block($this->form($this->schema()))->getConfig()['context']['productId']);
    }

    public function testItReadsTheProductIdFromTheProductQueryParameterOnStandaloneFormPages(): void
    {
        $this->fullActionName = 'furanform_form_view';
        $this->requestParams = ['product' => '7', 'id' => '99'];
        $this->assertSame(7, $this->block($this->form($this->schema()))->getConfig()['context']['productId']);

        $this->requestParams = ['product' => 'abc'];
        $this->assertNull($this->block($this->form($this->schema()))->getConfig()['context']['productId']);

        $this->requestParams = ['product' => '-3'];
        $this->assertNull($this->block($this->form($this->schema()))->getConfig()['context']['productId']);
    }

    public function testItMarksTheFormContextEagerOnlyWhenAccessRulesSurveyModeOrACustomerOrAddressPrefillSourceNeedIt(): void
    {
        $this->pool = new Pool(['text' => $this->pool->get('text'), 'hidden' => new \Furan\FormBuilder\Model\FieldType\Hidden()]);
        $eager = fn (array $settings, ?array $pages = null, int $product = 0): bool => (function () use ($settings, $pages, $product) {
            $this->fullActionName = $product > 0 ? 'catalog_product_view' : 'cms_page_view';
            $this->requestParams = ['id' => (string)$product];
            return $this->block($this->form($this->schema($pages), $settings))->getConfig()['context']['eager'];
        })();
        $field = static fn (string $type, string $prefill): array => [['id' => 'p_aaaa', 'title' => '', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => $type, 'name' => 'a', 'prefill' => $prefill],
        ]]];

        $this->assertFalse($eager([]));
        $this->assertFalse($eager(['access_groups' => [], 'survey_mode' => 'off', 'access_login_required' => false]));
        $this->assertTrue($eager(['access_login_required' => true]));
        $this->assertTrue($eager(['access_groups' => [0]]));
        $this->assertTrue($eager(['survey_mode' => 'once']));
        $this->assertTrue($eager([], $field('text', 'customer.firstname')));
        $this->assertTrue($eager([], $field('address', 'address.billing')));
        $this->assertFalse($eager([], $field('text', '')));
        $this->assertTrue($eager([], $field('text', 'product.name'), 5));
        $this->assertFalse($eager([], $field('text', 'product.name')));
        $this->assertFalse($eager([], $field('hidden', 'product.sku'), 5));
    }

    public function testItLoadsTheTokenEagerlyWhenAConditionUsesARelativeDate(): void
    {
        $this->fullActionName = 'cms_page_view';
        $this->requestParams = ['id' => '0'];
        $relative = ['field' => 'a', 'operator' => 'greater_than', 'value_date' => ['days' => 3]];
        $plain = ['field' => 'a', 'operator' => 'equals', 'value' => 'x'];
        $condition = static fn (array ...$rules): array => ['match' => 'all', 'rules' => $rules];
        $nest = static fn (array $rule, int $levels): array => $levels === 0
            ? $rule : ['match' => 'any', 'rules' => [$plain, $rule]];
        $pagesWith = static fn (array $item): array => [['id' => 'p_aaaa', 'title' => '', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'date', 'name' => 'a'], $item,
        ]]];
        $eager = fn (array $pages): bool => $this->block($this->form($this->schema($pages)))->getConfig()['context']['eager'];
        $field = static fn (?array $condition): array => ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'text', 'name' => 'b', 'condition' => $condition];

        $this->assertTrue($eager($pagesWith($field($condition($relative)))));
        $this->assertTrue($eager($pagesWith($field($condition($nest($nest($relative, 1), 1))))));
        $this->assertFalse($eager($pagesWith($field($condition($plain)))));
        $this->assertFalse($eager($pagesWith($field(null))));
        $section = ['id' => 's_aaaa', 'kind' => 'section', 'title' => '', 'condition' => $condition($relative), 'items' => []];
        $this->assertTrue($eager($pagesWith($section)));
        $page = ['id' => 'p_aaaa', 'title' => '', 'condition' => $condition($relative), 'items' => []];
        $this->assertTrue($eager([$page]));
    }

    public function testItLoadsTheTokenEagerlyWhenAPageSkipUsesARelativeDate(): void
    {
        $this->fullActionName = 'cms_page_view';
        $this->requestParams = ['id' => '0'];
        $skip = static fn (array $rule): array => ['match' => 'all', 'target' => 'p_bbbb', 'rules' => [
            ['match' => 'any', 'rules' => [$rule]],
        ]];
        $pages = static fn (array $rule): array => [
            ['id' => 'p_aaaa', 'title' => '', 'condition' => null, 'skip' => $skip($rule), 'items' => [
                ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'date', 'name' => 'a'],
            ]],
            ['id' => 'p_bbbb', 'title' => '', 'condition' => null, 'skip' => null, 'items' => []],
        ];
        $eager = fn (array $pages): bool => $this->block($this->form($this->schema($pages)))->getConfig()['context']['eager'];

        $this->assertTrue($eager($pages(['field' => 'a', 'operator' => 'less_than', 'value_date' => ['days' => -1]])));
        $this->assertFalse($eager($pages(['field' => 'a', 'operator' => 'equals', 'value' => 'x'])));
    }

    public function testItFetchesTheTokenEagerlyForACustomCustomerGroupSource(): void
    {
        $field = static fn (string $prefill): array => [['id' => 'p_aaaa', 'title' => '', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a', 'prefill' => $prefill],
        ]]];
        $eager = function (string $prefill, int $product = 0) use ($field): bool {
            $this->fullActionName = $product > 0 ? 'catalog_product_view' : 'cms_page_view';
            $this->requestParams = ['id' => (string)$product];
            return $this->block($this->form($this->schema($field($prefill))))->getConfig()['context']['eager'];
        };

        $this->assertTrue($eager('loyalty.tier'));
        $this->assertFalse($eager('product.stock'));
        $this->assertTrue($eager('product.stock', 5));
        $this->assertFalse($eager('loyalty.removed'));
    }

    public function testItAddsTheLoginUrlAndTheGateMessagesToTheFormConfig(): void
    {
        $config = $this->block($this->form($this->schema()))->getConfig();

        $this->assertSame('https://shop.test/furanform/account/login', $config['context']['loginUrl']);
        foreach (['gateLogin', 'gateGroup', 'gateAnswered', 'gateLoginLink', 'gateRegisterLink'] as $key) {
            $this->assertNotSame('', $config['messages'][$key] ?? '', $key);
        }
    }

    public function testItKeepsTheFormAndWidgetBlocksFreeOfACacheLifetimeSoTheProductIdIsNeverFrozen(): void
    {
        foreach ([\Furan\FormBuilder\Block\Form::class, \Furan\FormBuilder\Block\Widget\Form::class] as $class) {
            $source = (string)file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString('cache_lifetime', $source);
            $this->assertStringNotContainsString('CACHE_LIFETIME', $source);
        }
        $this->assertNull($this->block($this->form($this->schema()))->getCacheLifetime());
    }

    private ManagerInterface&Stub $eventManager;
    private string $fullActionName = 'cms_page_view';
    /** @var array<string, string> */
    private array $requestParams = [];

    public function testItLetsAStorefrontConfigBuildObserverAddKeysToTheFormConfig(): void
    {
        $this->asserted = ['eventManager'];
        $block = $this->block($this->form(['pages' => []]));
        $this->eventManager->expects($this->once())->method('dispatch')->with(
            'furan_formbuilder_storefront_config_build',
            $this->callback(static fn (array $d): bool => $d['form']->getId() === 12
                && $d['store_id'] === 1
                && $d['config'] instanceof \Magento\Framework\DataObject
                && $d['config']->getData('formId') === 12)
        )->willReturnCallback(static function (string $name, array $d): void {
            $d['config']->setData('crm', ['campaign' => 'spring']);
            $d['config']->unsetData('formId');
            $d['config']->setData('submitUrl', 'https://evil.test/');
            $d['config']->unsetData('schema');
        });

        $config = $block->getConfig();

        $this->assertSame(['campaign' => 'spring'], $config['crm']);
        $this->assertSame(12, $config['formId']);
        $this->assertStringContainsString('furanform/form/submit', $config['submitUrl']);
        $this->assertSame(['public' => true, 'pages' => []], $config['schema']);
        $this->assertArrayHasKey('tokenUrl', $config);
    }

    private function form(array $schema, array $settings = []): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(12);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getSettings')->willReturn($settings + [
            'submit_label' => 'Submit', 'prev_label' => 'Back', 'next_label' => 'Next',
            'draft_mode' => 'session', 'show_progress' => true,
            'success_message' => 'Thanks',
        ]);
        return $form;
    }

    private function moduleConfig(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($this->formsEnabled);
        $config->method('getDraftTtlHours')->willReturn(24);
        $config->method('getMaxUploadMb')->willReturn(5);
        foreach ($this->mapConfig as $getter => $value) {
            $config->method($getter)->willReturn($value);
        }
        return $config;
    }

    private function clock(): \Magento\Framework\Stdlib\DateTime\DateTime
    {
        $clock = $this->createStub(\Magento\Framework\Stdlib\DateTime\DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(1800000000);
        return $clock;
    }

    private function block(?FormInterface $form, bool $lazy = false): TestableForm
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $request = $this->createStub(Http::class);
        $request->method('isSecure')->willReturn(true);
        $request->method('getFullActionName')->willReturn($this->fullActionName);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->requestParams[$key] ?? $default);
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $this->eventManager = $this->double('eventManager', ManagerInterface::class);
        $context->method('getEventManager')->willReturn($this->eventManager);
        $context->method('getScopeConfig')->willReturn($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class));
        $context->method('getLayout')->willReturn($this->layout);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEscaper')->willReturn(\Furan\FormBuilder\Test\Unit\Helper\EscaperFactory::create());

        $publicSchema = $this->createStub(PublicSchema::class);
        $publicSchema->method('build')->willReturnCallback(static fn (array $s): array => ['public' => true] + $s);
        $config = $this->moduleConfig();

        $reCaptcha = $this->createStub(ReCaptcha::class);
        $reCaptcha->method('getUiConfig')->willReturn($this->recaptchaUi);

        $block = new TestableForm(
            $context,
            new ConfigBuilder(
                $publicSchema,
                $this->pool,
                $config,
                new Json(),
                $this->logger,
                $reCaptcha,
                new Eagerness(
                    \Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools::builtIn([
                        'loyalty.tier' => new \Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource('loyalty.tier', 'customer'),
                        'product.stock' => new \Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource('product.stock', 'product'),
                    ]),
                    $this->pool
                ),
                $this->eventManager
            ),
            $this->pool,
            $config,
            $this->logger,
            new MiniMarkdown(),
            new \Furan\FormBuilder\Model\Form\ScheduleGate($this->clock()),
            $this->countryOptions ?? $this->createStub(CountryOptions::class),
            new \Magento\Framework\Serialize\Serializer\JsonHexTag(),
            $this->scripts,
            $this->styles
        );
        $block->stubs = $this->tmpTemplates;
        if (!$lazy && $form !== null) {
            $block->setForm($form);
        }
        return $block;
    }

    /** @var array<string, string> */
    private array $tmpTemplates = [];

    private function stubTemplate(string $templateId, string $php): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ffb') . '.phtml';
        file_put_contents($file, $php);
        $this->tmpFiles[] = $file;
        $this->tmpTemplates[$templateId] = $file;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        return new DOMXPath($dom);
    }
    private function useNewsletter(callable $available): void
    {
        $bridge = $this->createStub(\Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturnCallback($available);
        $text = $this->createStub(FieldTypeInterface::class);
        $text->method('getTemplate')->willReturn('Furan_FormBuilder::item/text.phtml');
        $this->pool = new Pool(['text' => $text, 'newsletter' => new \Furan\FormBuilder\Model\FieldType\Newsletter($bridge)]);
    }

    private function newsletterPages(): array
    {
        return [['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_aaaa', 'kind' => 'field', 'type' => 'text', 'name' => 'a'],
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'newsletter', 'name' => 'news'],
        ]]];
    }

    private function configFieldNames(array $config): array
    {
        return array_column($config['schema']['pages'][0]['items'], 'name');
    }

    public function testItSkipsAStoredNewsletterFieldOnTheStorefrontWhenUnavailable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->useNewsletter(static fn (?int $store): bool => false);
        $this->stubTemplate('Furan_FormBuilder::item/text.phtml', '<input data-stub="<?= $escaper->escapeHtmlAttr($block->getData(\'item\')[\'name\']) ?>">');
        $this->stubTemplate('Furan_FormBuilder::item/newsletter.phtml', '<input data-stub="news">');
        $logger->expects($this->never())->method('error');
        $block = $this->block($this->form($this->schema($this->newsletterPages())));

        $this->assertSame(['a'], $this->configFieldNames($block->getConfig()));
        $this->assertSame('', $block->renderItem($this->newsletterPages()[0]['items'][1]));
        $this->assertSame(0, $this->xpath($block->toHtml())->query('//input[@data-stub="news"]')->length);
    }

    public function testItKeepsPageAndSectionMetadataWhenDroppingAnUnavailableField(): void
    {
        $this->useNewsletter(static fn (?int $store): bool => false);
        $condition = ['match' => 'all', 'rules' => []];
        $pages = [['id' => 'p_aaaa', 'title' => 'P', 'condition' => $condition, 'items' => [
            ['id' => 's_bbbb', 'kind' => 'section', 'title' => 'Sec', 'condition' => null, 'skip' => $condition, 'items' => [
                ['id' => 'f_cccc', 'kind' => 'field', 'type' => 'newsletter', 'name' => 'news'],
                ['id' => 'f_dddd', 'kind' => 'field', 'type' => 'text', 'name' => 'a'],
            ]],
        ]]];

        $page = $this->block($this->form($this->schema($pages)))->getRenderSchema()['pages'][0];

        $this->assertSame($condition, $page['condition']);
        $this->assertSame(['f_dddd'], array_column($page['items'][0]['items'], 'id'));
        $this->assertSame('Sec', $page['items'][0]['title']);
        $this->assertNull($page['items'][0]['condition']);
        $this->assertSame($condition, $page['items'][0]['skip']);
    }

    public function testItHidesTheNewsletterFieldOnAStoreViewWhereNewslettersAreOff(): void
    {
        $this->useNewsletter(static fn (?int $store): bool => $store !== 1);
        $this->assertSame(['a'], $this->configFieldNames($this->block($this->form($this->schema($this->newsletterPages())))->getConfig()));

        $this->useNewsletter(static fn (?int $store): bool => true);
        $this->assertSame(['a', 'news'], $this->configFieldNames($this->block($this->form($this->schema($this->newsletterPages())))->getConfig()));
    }

    public function testItKeepsTheTokenLazyForAFormWhoseOnlyEagerReasonWouldBeANewsletterField(): void
    {
        $this->useNewsletter(static fn (?int $store): bool => true);
        $pages = [['id' => 'p_aaaa', 'title' => 'P', 'items' => [
            ['id' => 'f_bbbb', 'kind' => 'field', 'type' => 'newsletter', 'name' => 'news'],
        ]]];

        $this->assertFalse($this->block($this->form($this->schema($pages)))->getConfig()['context']['eager']);
    }
}
