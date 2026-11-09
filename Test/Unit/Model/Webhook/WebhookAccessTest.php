<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\WebhookAccess;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class WebhookAccessTest extends TestCase
{
    /** @param string[] $granted */
    private function auth(array $granted): AuthorizationInterface
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r): bool => in_array($r, $granted, true));

        return $auth;
    }

    private function endpoint(bool $personal): EndpointView
    {
        $endpoint = $this->createStub(EndpointView::class);
        $endpoint->method('includePersonal')->willReturn($personal);

        return $endpoint;
    }

    public function testItNeedsViewManageAndExportToChange(): void
    {
        $all = [WebhookAccess::VIEW, WebhookAccess::MANAGE, WebhookAccess::EXPORT];
        $this->assertTrue(WebhookAccess::canChange($this->auth($all)));
        foreach ($all as $missing) {
            $this->assertFalse(WebhookAccess::canChange($this->auth(array_diff($all, [$missing]))));
        }
        $this->assertTrue(WebhookAccess::canChange($this->auth([WebhookAccess::VIEW, WebhookAccess::EXPORT, 'x::replay']), 'x::replay'));
    }

    public function testItNeedsThePersonalPermissionForResponsesOfAPersonalEndpoint(): void
    {
        $all = [WebhookAccess::VIEW, WebhookAccess::MANAGE, WebhookAccess::EXPORT];

        $this->assertTrue(WebhookAccess::canReadResponses($this->auth($all), $this->endpoint(false)));
        $this->assertFalse(WebhookAccess::canReadResponses($this->auth($all), $this->endpoint(true)));
        $this->assertTrue(WebhookAccess::canReadResponses($this->auth([...$all, WebhookAccess::PERSONAL]), $this->endpoint(true)));
        $this->assertFalse(WebhookAccess::canReadResponses($this->auth([WebhookAccess::PERSONAL]), $this->endpoint(false)));
    }
}
