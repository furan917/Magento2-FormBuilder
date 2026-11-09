<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Settings;

use Furan\FormBuilder\Model\Settings\ProtectedSettings;
use Furan\FormBuilder\Test\Unit\Helper\ArraySettingsContributor;
use PHPUnit\Framework\TestCase;

class ProtectedSettingsTest extends TestCase
{
    private function policy(): ProtectedSettings
    {
        return new ProtectedSettings(ArraySettingsContributor::pool(
            new ArraySettingsContributor('acme_crm', null, 'Acme_Crm::settings'),
            new ArraySettingsContributor('open_ext', null, null)
        ));
    }

    public function testItKeepsProtectedSubmissionSettingsAndContributorSettingsAtTheirStoredValuesForAnAdminWithoutTheResource(): void
    {
        $posted = [
            'submit_label' => 'Go',
            'notify_enabled' => true,
            'notify_cc' => 'evil@example.com',
            'ext' => ['acme_crm' => ['list_id' => 'evil'], 'open_ext' => ['list_id' => 'new']],
        ];
        $stored = [
            'notify_enabled' => false,
            'store_ip' => true,
            'ext' => ['acme_crm' => ['list_id' => 'kept'], 'open_ext' => ['list_id' => 'old']],
        ];

        $result = $this->policy()->restrict($posted, $stored, static fn (string $resource): bool => false);

        $this->assertSame('Go', $result['submit_label']);
        $this->assertFalse($result['notify_enabled']);
        $this->assertTrue($result['store_ip']);
        $this->assertArrayNotHasKey('notify_cc', $result);
        $this->assertSame(['list_id' => 'kept'], $result['ext']['acme_crm']);
        $this->assertSame(['list_id' => 'new'], $result['ext']['open_ext']);
    }

    public function testItLeavesPostedSettingsAloneForAnAdminWithEveryResource(): void
    {
        $posted = ['notify_cc' => 'a@example.com', 'ext' => ['acme_crm' => ['list_id' => 'new']]];

        $this->assertSame($posted, $this->policy()->restrict($posted, ['notify_cc' => 'old'], static fn (string $resource): bool => true));
    }

    public function testItHidesProtectedSettingsKeysFromTheBuilderForAnAdminWithoutTheResource(): void
    {
        $this->assertSame(
            ['notify_enabled', 'notify_recipients', 'notify_cc', 'notify_bcc', 'notify_subject', 'notify_routes', 'notify_when', 'store_ip'],
            $this->policy()->hiddenKeys(static fn (string $resource): bool => false)
        );
        $this->assertSame([], $this->policy()->hiddenKeys(static fn (string $resource): bool => true));
    }
}
