<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

class RecaptchaConfigTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../etc/';

    public function testItAddsAFormBuilderTypeFieldToTheRecaptchaStorefrontSection(): void
    {
        $system = simplexml_load_file(self::ETC . 'adminhtml/system.xml');
        $field = $system->xpath(
            '/config/system/section[@id="recaptcha_frontend"]/group[@id="type_for"]/field[@id="furan_formbuilder"]'
        );
        $this->assertCount(1, $field);
        $this->assertSame('select', (string)$field[0]['type']);
        $this->assertSame('1', (string)$field[0]['showInDefault']);
        $this->assertSame('1', (string)$field[0]['showInWebsite']);
        $this->assertSame('0', (string)$field[0]['showInStore']);
        $this->assertSame(
            'Magento\ReCaptchaAdminUi\Model\OptionSource\Type',
            trim((string)$field[0]->source_model)
        );
        $this->assertSame('Enable for Form Builder Forms', trim((string)$field[0]->label));
        $this->assertGreaterThan(
            0,
            count($system->xpath('/config/system/section[@id="furan_formbuilder"]'))
        );
    }

    public function testItDefaultsTheFormBuilderRecaptchaTypeToOff(): void
    {
        $config = simplexml_load_file(self::ETC . 'config.xml');
        $node = $config->xpath('/config/default/recaptcha_frontend/type_for/furan_formbuilder');
        $this->assertCount(1, $node);
        $this->assertSame('', trim((string)$node[0]));
    }

    public function testItListensOnlyToTheRecaptchaStorefrontSectionSavedEvent(): void
    {
        $events = simplexml_load_file(self::ETC . 'adminhtml/events.xml');
        $all = $events->xpath('/config/event');
        $this->assertCount(1, $all);
        $this->assertSame('admin_system_config_changed_section_recaptcha_frontend', (string)$all[0]['name']);
        $observer = $all[0]->observer;
        $this->assertSame(
            'Furan\FormBuilder\Observer\RefreshFormsOnRecaptchaChange',
            (string)$observer['instance']
        );
    }

    public function testItRegistersTheAdminNoticeAndSuggestsTheRecaptchaModules(): void
    {
        $di = simplexml_load_file(self::ETC . 'adminhtml/di.xml');
        $item = $di->xpath(
            '/config/type[@name="Magento\Framework\Notification\MessageList"]/arguments/argument[@name="messages"]'
            . '/item[@name="furan_formbuilder_recaptcha_unavailable"]'
        );
        $this->assertCount(1, $item);
        $this->assertSame(
            'Furan\FormBuilder\Model\System\Message\RecaptchaUnavailable',
            trim((string)$item[0])
        );
        $composer = json_decode((string)file_get_contents(self::ETC . '../composer.json'), true);
        foreach (['magento/module-re-captcha-frontend-ui', 'magento/module-re-captcha-version-2-checkbox',
            'magento/module-re-captcha-version-2-invisible', 'magento/module-re-captcha-version-3-invisible'] as $p) {
            $this->assertArrayHasKey($p, $composer['suggest']);
            $this->assertArrayNotHasKey($p, $composer['require']);
        }
    }
}
