<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

class ConfigSkeletonTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../etc/';

    public function testItDeclaresConfigGroupsForMapsCustomersPrivacyAndAnalyticsWithDocumentedDefaults(): void
    {
        $config = simplexml_load_file(self::ETC . 'config.xml');
        $expected = [
            'maps/provider' => 'osm',
            'maps/google_api_key' => '',
            'maps/google_map_id' => '',
            'maps/tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'maps/max_zoom' => '19',
            'maps/search_enabled' => '0',
            'maps/search_endpoint' => 'https://nominatim.openstreetmap.org/search',
            'customers/claim_enabled' => '1',
            'customers/draft_sync' => '1',
            'customers/draft_days' => '30',
            'privacy/on_customer_delete' => 'anonymise',
            'analytics/enabled' => '1',
        ];
        foreach ($expected as $path => $value) {
            $node = $config->xpath('/config/default/furan_formbuilder/' . $path);
            $this->assertCount(1, $node, $path);
            $this->assertSame($value, (string)$node[0], $path);
        }
        $this->assertCount(1, $config->xpath('/config/default/furan_formbuilder/maps/attribution'));
        $this->assertCount(1, $config->xpath('/config/default/furan_formbuilder/privacy/retention_days'));
        $system = simplexml_load_file(self::ETC . 'adminhtml/system.xml');
        $this->assertCount(1, $system->xpath('//group[@id="privacy"]'));
        foreach (['maps', 'customers', 'analytics'] as $group) {
            $this->assertCount(1, $system->xpath('//group[@id="' . $group . '"]'), $group);
        }
        $this->assertSame(
            'Hides the analytics page only. Submissions are still journaled and folded into the statistics, '
            . 'so re-enabling shows complete data.',
            trim((string)$system->xpath('//group[@id="analytics"]/field[@id="enabled"]/comment')[0])
        );
    }

    public function testItShowsTheMapsGroupAndEveryMapsFieldAtStoreViewScope(): void
    {
        $system = simplexml_load_file(self::ETC . 'adminhtml/system.xml');
        $group = $system->xpath('//group[@id="maps"]')[0];
        $this->assertSame('1', (string)$group['showInStore']);
        $fields = $group->xpath('field');
        $this->assertCount(8, $fields);
        foreach ($fields as $field) {
            foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $scope) {
                $this->assertSame('1', (string)$field[$scope], $field['id'] . ' ' . $scope);
            }
        }
    }

    public function testItDeclaresTheGoogleApiKeyWithTheEncryptedBackendModelInSystemXmlAndConfigXml(): void
    {
        $encrypted = 'Magento\Config\Model\Config\Backend\Encrypted';
        $system = simplexml_load_file(self::ETC . 'adminhtml/system.xml');
        $field = $system->xpath('//group[@id="maps"]/field[@id="google_api_key"]')[0];
        $this->assertSame('obscure', (string)$field['type']);
        $this->assertSame($encrypted, (string)$field->backend_model);
        $config = simplexml_load_file(self::ETC . 'config.xml');
        $node = $config->xpath('/config/default/furan_formbuilder/maps/google_api_key')[0];
        $this->assertSame($encrypted, (string)$node['backend_model']);
    }

    public function testItMarksTheGoogleApiKeyAsASensitiveConfigPath(): void
    {
        $di = simplexml_load_file(self::ETC . 'di.xml');
        $item = $di->xpath(
            '//type[@name="Magento\Config\Model\Config\TypePool"]/arguments/argument[@name="sensitive"]'
            . '/item[@name="furan_formbuilder/maps/google_api_key"]'
        );
        $this->assertCount(1, $item);
        $this->assertSame('1', trim((string)$item[0]));
    }

    public function testItOffersTheWebhookRetentionInTheDefaultScopeOnly(): void
    {
        $config = simplexml_load_file(self::ETC . 'config.xml');
        $this->assertSame('30', (string)$config->xpath('/config/default/furan_formbuilder/webhooks/retention_days')[0]);
        $system = simplexml_load_file(self::ETC . 'adminhtml/system.xml');
        $field = $system->xpath('//group[@id="webhooks"]/field[@id="retention_days"]')[0];
        $this->assertSame('1', (string)$field['showInDefault']);
        $this->assertSame('', (string)$field['showInWebsite']);
        $this->assertSame('', (string)$field['showInStore']);
        $this->assertSame('', (string)$system->xpath('//group[@id="webhooks"]')[0]['showInWebsite']);
        $this->assertStringContainsString('validate-digits', (string)$field->validate);
    }

    public function testItOffersRestoringTheDefaultOnEveryWebsiteOrStoreFieldWithADefault(): void
    {
        $config = simplexml_load_file(self::ETC . 'config.xml');
        $system = simplexml_load_file(self::ETC . 'adminhtml/system.xml');
        $missing = [];
        foreach ($system->xpath('//section[@id="furan_formbuilder"]/group/field') as $field) {
            $group = $field->xpath('..')[0];
            $path = 'furan_formbuilder/' . $group['id'] . '/' . $field['id'];
            $default = $config->xpath('/config/default/' . $path);
            $scoped = (string)$field['showInWebsite'] === '1' || (string)$field['showInStore'] === '1';
            if ($scoped && $default && trim((string)$default[0]) !== '' && (string)$field['type'] !== 'obscure'
                && (string)$field['canRestore'] !== '1'
            ) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing);
    }
}
