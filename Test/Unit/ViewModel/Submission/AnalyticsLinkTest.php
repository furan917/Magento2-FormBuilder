<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\ViewModel\Submission;

use Furan\FormBuilder\ViewModel\Submission\AnalyticsLink;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class AnalyticsLinkTest extends TestCase
{
    private function link(mixed $formId, bool $allowed): AnalyticsLink
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'form_id' ? $formId : null);
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $allowed && $resource === 'Furan_FormBuilder::analytics'
        );
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $route . '?' . http_build_query($params)
        );

        return new AnalyticsLink($request, $authorization, $url);
    }

    public function testItLinksToTheAnalyticsOfTheFormTheGridIsOpenedFor(): void
    {
        $this->assertSame('furanform/analytics/index?form_id=7', $this->link('7', true)->url());
    }

    public function testItShowsNoLinkWithoutOneFormOrWithoutTheAnalyticsPermission(): void
    {
        $this->assertSame('', $this->link('7', false)->url());
        $this->assertSame('', $this->link(null, true)->url());
        $this->assertSame('', $this->link('0', true)->url());
        $this->assertSame('', $this->link('7 OR 1', true)->url());
        $this->assertSame('', $this->link(['7'], true)->url());
    }
}
