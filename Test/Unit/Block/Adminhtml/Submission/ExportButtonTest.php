<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Submission;

use Furan\FormBuilder\Block\Adminhtml\Submission\ExportButton;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class ExportButtonTest extends TestCase
{
    public function testItShowsTheExportButtonOnlyWithAFormIdAndTheExportAcl(): void
    {
        $this->assertSame([], $this->button(null, true)->getButtonData());
        $this->assertSame([], $this->button('0', true)->getButtonData());
        $this->assertSame([], $this->button('abc', true)->getButtonData());
        $this->assertSame([], $this->button('12', false)->getButtonData());

        $data = $this->button('12', true)->getButtonData();
        $this->assertSame("location.href = 'url:*/*/export:12';", $data['on_click']);
        $this->assertSame('Export CSV', (string)$data['label']);
    }

    private function button(?string $formId, bool $allowed): ExportButton
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('form_id')->willReturn($formId);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('Furan_FormBuilder::submissions_export')->willReturn($allowed);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn(string $path, array $params): string => 'url:' . $path . ':' . $params['form_id']
        );
        return new ExportButton($request, $authorization, $url);
    }
}
