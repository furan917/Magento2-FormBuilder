<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

class EmailTemplateConfigTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../etc/';
    private const SETTINGS = ['notification', 'autoresponder', 'reply', 'customer_reply', 'claim'];

    public function testItShowsTheModuleTemplateAsTheDefaultOptionForEachEmailSetting(): void
    {
        $system = simplexml_load_file(self::ETC . 'adminhtml/system.xml');
        $config = simplexml_load_file(self::ETC . 'config.xml');
        $templates = simplexml_load_file(self::ETC . 'email_templates.xml');

        foreach (self::SETTINGS as $setting) {
            $id = 'furan_formbuilder_email_' . $setting . '_template';
            $field = $system->xpath(
                '/config/system/section[@id="furan_formbuilder"]/group[@id="email"]/field[@id="' . $setting . '_template"]'
            );
            $this->assertCount(1, $field, $setting);
            $this->assertSame('select', (string)$field[0]['type'], $setting);
            $this->assertSame('1', (string)$field[0]['showInStore'], $setting);
            $this->assertSame(
                'Magento\Config\Model\Config\Source\Email\Template',
                trim((string)$field[0]->source_model),
                $setting
            );

            $default = $config->xpath('/config/default/furan_formbuilder/email/' . $setting . '_template');
            $this->assertSame($id, trim((string)$default[0]), $setting);

            $template = $templates->xpath('/config/template[@id="' . $id . '"]');
            $this->assertCount(1, $template, $setting);
            $this->assertFileExists(self::ETC . '../view/frontend/email/' . (string)$template[0]['file'], $setting);
        }
        $this->assertCount(count(self::SETTINGS), $templates->xpath('/config/template'));
    }
}
