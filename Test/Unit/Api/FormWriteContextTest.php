<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Api;

use Furan\FormBuilder\Api\FormWriteContext;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class FormWriteContextTest extends TestCase
{
    public function testItBuildsAnAdminWriteContextThatFollowsTheAdminsPermissionsAndCarriesTheAdminId(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $r): bool => $r === 'Furan_FormBuilder::submissions'
        );

        $context = FormWriteContext::forAdmin($authorization, 7);

        $this->assertTrue($context->isAllowed('Furan_FormBuilder::submissions'));
        $this->assertFalse($context->isAllowed('Furan_FormBuilder::webhooks_personal'));
        $this->assertSame(7, $context->adminId);
    }

    public function testItAllowsEverythingWithoutAnAdminForTrustedCallers(): void
    {
        $context = FormWriteContext::allowAll();

        $this->assertTrue($context->isAllowed('Any::resource'));
        $this->assertNull($context->adminId);
    }
}
