<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use PHPUnit\Framework\TestCase;

class AclMapTest extends TestCase
{
    /** Controller => [ACL resource, HTTP verb]. A new controller fails this test until it is added here. */
    private const MAP = [
        'Form\\Index' => ['Furan_FormBuilder::forms', 'GET'],
        'Form\\Edit' => ['Furan_FormBuilder::forms', 'GET'],
        'Form\\NewAction' => ['Furan_FormBuilder::forms_save', 'GET'],
        'Form\\Save' => ['Furan_FormBuilder::forms_save', 'POST'],
        'Form\\Duplicate' => ['Furan_FormBuilder::forms_save', 'POST'],
        'Form\\MassStatus' => ['Furan_FormBuilder::forms_save', 'POST'],
        'Form\\Delete' => ['Furan_FormBuilder::forms_delete', 'POST'],
        'Form\\MassDelete' => ['Furan_FormBuilder::forms_delete', 'POST'],
        'Submission\\Index' => ['Furan_FormBuilder::submissions', 'GET'],
        'Submission\\View' => ['Furan_FormBuilder::submissions', 'GET'],
        'Submission\\Open' => ['Furan_FormBuilder::submissions', 'GET'],
        'Submission\\Download' => ['Furan_FormBuilder::submissions_files', 'GET'],
        'Submission\\Export' => ['Furan_FormBuilder::submissions_export', 'GET'],
        'Submission\\ExportFile' => ['Furan_FormBuilder::submissions_export', 'GET'],
        'Submission\\Delete' => ['Furan_FormBuilder::submissions_delete', 'POST'],
        'Submission\\MassDelete' => ['Furan_FormBuilder::submissions_delete', 'POST'],
        'Submission\\MassStatus' => ['Furan_FormBuilder::submissions_reply', 'POST'],
        'Submission\\SaveStatus' => ['Furan_FormBuilder::submissions_reply', 'POST'],
        'Submission\\Reply' => ['Furan_FormBuilder::submissions_reply', 'POST'],
        'Submission\\RevokeLinks' => ['Furan_FormBuilder::submissions_reply', 'POST'],
        'Rule\\Index' => ['Furan_FormBuilder::rules', 'GET'],
        'Rule\\Edit' => ['Furan_FormBuilder::rules', 'GET'],
        'Rule\\Save' => ['Furan_FormBuilder::rules_save', 'POST'],
        'Rule\\Delete' => ['Furan_FormBuilder::rules_save', 'POST'],
        'FieldGroup\\Index' => ['Furan_FormBuilder::field_groups', 'GET'],
        'FieldGroup\\Edit' => ['Furan_FormBuilder::field_groups', 'GET'],
        'FieldGroup\\Save' => ['Furan_FormBuilder::field_groups_edit', 'POST'],
        'FieldGroup\\Delete' => ['Furan_FormBuilder::field_groups_delete', 'POST'],
        'FieldGroup\\Create' => ['Furan_FormBuilder::field_groups_edit', 'POST'],
        'FieldGroup\\Palette' => ['Furan_FormBuilder::field_groups', 'POST'],
        'FieldGroup\\Insert' => ['Furan_FormBuilder::field_groups', 'POST'],
        'Webhook\\Index' => ['Furan_FormBuilder::webhooks', 'GET'],
        'Webhook\\Edit' => ['Furan_FormBuilder::webhooks', 'GET'],
        'Webhook\\NewAction' => ['Furan_FormBuilder::webhooks_manage', 'GET'],
        'Webhook\\Save' => ['Furan_FormBuilder::webhooks_manage', 'POST'],
        'Webhook\\Delete' => ['Furan_FormBuilder::webhooks_manage', 'POST'],
        'Webhook\\RotateSecret' => ['Furan_FormBuilder::webhooks_manage', 'POST'],
        'Webhook\\TestSend' => ['Furan_FormBuilder::webhooks_manage', 'POST'],
        'WebhookDelivery\\Index' => ['Furan_FormBuilder::webhooks', 'GET'],
        'WebhookDelivery\\View' => ['Furan_FormBuilder::webhooks', 'GET'],
        'WebhookDelivery\\Redeliver' => ['Furan_FormBuilder::webhooks_replay', 'POST'],
        'WebhookDelivery\\Resend' => ['Furan_FormBuilder::webhooks_replay', 'POST'],
        'Analytics\\Index' => ['Furan_FormBuilder::analytics', 'GET'],
        'Analytics\\QueueRebuild' => ['Furan_FormBuilder::analytics', 'POST'],
        'Privacy\\Index' => ['Furan_FormBuilder::privacy', 'GET'],
        'Privacy\\Export' => ['Furan_FormBuilder::privacy', 'POST'],
        'Privacy\\Erase' => ['Furan_FormBuilder::privacy', 'POST'],
        'Privacy\\Preview' => ['Furan_FormBuilder::privacy', 'POST'],
    ];

    public function testEveryAdminControllerUsesItsIntendedResourceAndVerb(): void
    {
        $root = __DIR__ . '/../../../../';
        $acl = simplexml_load_file($root . 'etc/acl.xml');
        $found = [];
        foreach (glob($root . 'Controller/Adminhtml/*/*.php') as $file) {
            $short = basename(dirname($file)) . '\\' . basename($file, '.php');
            $class = 'Furan\\FormBuilder\\Controller\\Adminhtml\\' . $short;
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $found[] = $short;
            $this->assertArrayHasKey($short, self::MAP, $short . ' has no ACL mapping');
            [$resource, $verb] = self::MAP[$short];
            $this->assertSame($resource, $class::ADMIN_RESOURCE, $short);
            $this->assertCount(1, $acl->xpath(sprintf('//resource[@id="%s"]', $resource)), $resource);
            $this->assertSame($verb === 'GET', is_subclass_of($class, HttpGetActionInterface::class), $short);
            $this->assertSame($verb === 'POST', is_subclass_of($class, HttpPostActionInterface::class), $short);
        }
        sort($found);
        $expected = array_keys(self::MAP);
        sort($expected);
        $this->assertSame($expected, $found);
    }

    public function testMenuItemsUseTheViewResources(): void
    {
        $menu = (string)file_get_contents(__DIR__ . '/../../../../etc/adminhtml/menu.xml');
        foreach (['forms', 'submissions', 'rules', 'field_groups', 'webhooks', 'privacy'] as $resource) {
            $this->assertStringContainsString('resource="Furan_FormBuilder::' . $resource . '"', $menu);
        }
    }

    public function testEveryAclTitleFitsTheSchemaMaximumLength(): void
    {
        $acl = new \DOMDocument();
        $acl->load(__DIR__ . '/../../../../etc/acl.xml');
        foreach ($acl->getElementsByTagName('resource') as $resource) {
            $title = $resource->getAttribute('title');
            $this->assertLessThanOrEqual(50, mb_strlen($title), $title);
        }
    }

    public function testItGatesThePrivacyPageAndBothActionsBehindTheOnePrivacyPermission(): void
    {
        // The export zip carries uploads and erase spans every form, so neither fits a narrower submissions permission.
        foreach (['Index', 'Export', 'Erase', 'Preview'] as $action) {
            $this->assertSame('Furan_FormBuilder::privacy', self::MAP['Privacy\\' . $action][0]);
        }
        $this->assertSame('POST', self::MAP['Privacy\\Export'][1]);
        $this->assertSame('POST', self::MAP['Privacy\\Erase'][1]);
    }
}
