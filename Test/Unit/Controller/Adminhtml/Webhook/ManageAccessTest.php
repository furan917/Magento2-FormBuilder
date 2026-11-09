<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Controller\Adminhtml\Webhook\Delete;
use Furan\FormBuilder\Controller\Adminhtml\Webhook\Edit;
use Furan\FormBuilder\Controller\Adminhtml\Webhook\Index;
use Furan\FormBuilder\Controller\Adminhtml\Webhook\NewAction;
use Furan\FormBuilder\Controller\Adminhtml\Webhook\Save;
use Furan\FormBuilder\Model\Webhook\EndpointAdmin;
use Furan\FormBuilder\Model\Webhook\SecretStash;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\Attributes\DataProvider;

class ManageAccessTest extends ControllerTestCase
{
    /** @param string[] $granted */
    private function build(string $class, array $granted): object
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => in_array($resource, $granted, true)
        );
        $this->context->method('getAuthorization')->willReturn($auth);
        $admin = $this->createStub(EndpointAdmin::class);

        return match ($class) {
            NewAction::class => new NewAction($this->context, $this->createStub(ForwardFactory::class)),
            Save::class => new Save(
                $this->context,
                $admin,
                $this->createStub(SecretStash::class),
                $this->createStub(DataPersistorInterface::class),
                $this->createStub(\Furan\FormBuilder\Model\Webhook\EndpointRepository::class)
            ),
            Delete::class => new Delete($this->context, $admin),
        };
    }

    private function allowed(object $controller): bool
    {
        return (new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller);
    }

    /** @return array<string, array{class-string}> */
    public static function managingControllers(): array
    {
        return ['new' => [NewAction::class], 'save' => [Save::class], 'delete' => [Delete::class]];
    }

    #[DataProvider('managingControllers')]
    public function testItRefusesToManageEndpointsWithoutTheExportPermission(string $class): void
    {
        $granted = ['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::webhooks_manage'];

        $this->assertFalse($this->allowed($this->build($class, $granted)));
    }

    #[DataProvider('managingControllers')]
    public function testItRefusesToManageEndpointsWithoutThePermissionToManage(string $class): void
    {
        $granted = ['Furan_FormBuilder::webhooks', 'Furan_FormBuilder::submissions_export'];

        $this->assertFalse($this->allowed($this->build($class, $granted)));
    }

    #[DataProvider('managingControllers')]
    public function testItAllowsManagingWithViewManageAndExport(string $class): void
    {
        $granted = [
            'Furan_FormBuilder::webhooks',
            'Furan_FormBuilder::webhooks_manage',
            'Furan_FormBuilder::submissions_export',
        ];

        $this->assertTrue($this->allowed($this->build($class, $granted)));
        $this->assertSame('Furan_FormBuilder::webhooks_manage', $class::ADMIN_RESOURCE);
    }

    public function testItLetsViewOnlyAdminsOpenTheListAndEditPages(): void
    {
        $this->assertSame('Furan_FormBuilder::webhooks', Index::ADMIN_RESOURCE);
        $this->assertSame('Furan_FormBuilder::webhooks', Edit::ADMIN_RESOURCE);
    }
}
