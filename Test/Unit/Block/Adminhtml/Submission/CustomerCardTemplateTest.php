<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Block\Adminhtml\Submission\View;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class CustomerCardTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../../view/adminhtml/templates/submission/view.phtml';

    private function card(array $override = []): array
    {
        return $override + [
            'type' => 'customer',
            'name' => '<b>Ann</b> Lee',
            'email' => 'ann@example.com',
            'group' => 'Wholesale',
            'orders' => ['count' => 3, 'lifetime' => 150.5, 'lastOrderAt' => '2026-02-01 10:00:00'],
            'others' => [['submission_id' => 9, 'form_id' => 1, 'summary' => '<i>hi</i>', 'created_at' => '2026-01-01 00:00:00', 'status' => 0]],
        ];
    }

    private function render(?array $card, string $customerUrl, ?array $product = null): string
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(5);
        $submission->method('getCustomerId')->willReturn($card && $card['type'] === 'customer' ? 12 : null);
        $block = $this->createStub(View::class);
        $block->method('getSubmission')->willReturn($submission);
        $block->method('getCustomerCard')->willReturn($card);
        $block->method('getCustomerUrl')->willReturn($customerUrl);
        $block->method('getProductContext')->willReturn($product);
        $block->method('formatLifetime')->willReturn('EUR 150.50');
        $block->method('formatSubmissionDate')->willReturn('1 Jan 2026');
        $block->method('getSubmissionUrl')->willReturn('https://admin.test/view/id/9');
        $block->method('getThread')->willReturn([]);
        $block->method('getCurrentStatus')->willReturn(0);
        $escaper = EscaperFactory::create();
        $renderer = static function () use ($block, $escaper): string {
            ob_start();
            include self::TEMPLATE;
            return (string)ob_get_clean();
        };

        return $renderer();
    }

    public function testItShowsTheCustomerOnceWithASingleLinkOnTheName(): void
    {
        $html = $this->render($this->card(), 'https://admin.test/customer/edit/id/12');

        $this->assertStringNotContainsString('Customer #12', $html);
        $this->assertSame(1, substr_count($html, 'href="https://admin.test/customer/edit/id/12"'));
        $this->assertMatchesRegularExpression('#<a href="https://admin.test/customer/edit/id/12">&lt;b&gt;Ann&lt;/b&gt; Lee</a>#', $html);
    }

    public function testItFallsBackToTheCustomerNumberWhenTheNameIsUnknown(): void
    {
        $html = $this->render($this->card(['name' => '']), 'https://admin.test/customer/edit/id/12');

        $this->assertSame(1, substr_count($html, 'Customer #12'));
        $this->assertMatchesRegularExpression('#<a href="https://admin.test/customer/edit/id/12">Customer \#12</a>#', $html);
        $this->assertStringNotContainsString('customer/edit', $this->render($this->card(['name' => '']), ''));
    }

    public function testItMarksAGuestOnlyOnce(): void
    {
        $html = $this->render($this->card(['type' => 'guest', 'name' => '', 'group' => '', 'orders' => null, 'others' => []]), '');

        $this->assertSame(1, substr_count(strip_tags($html), 'Guest'));
    }

    public function testItShowsTheCustomersNameGroupAndEmail(): void
    {
        $html = $this->render($this->card(), '');

        $this->assertStringContainsString('&lt;b&gt;Ann&lt;/b&gt; Lee', $html);
        $this->assertStringNotContainsString('<b>Ann', $html);
        $this->assertStringContainsString('Wholesale', $html);
        $this->assertStringContainsString('ann@example.com', $html);
        $this->assertStringContainsString('EUR 150.50', $html);
        $this->assertStringContainsString('&lt;i&gt;hi&lt;/i&gt;', $html);
        $this->assertStringContainsString('href="https://admin.test/view/id/9"', $html);
    }

    public function testItLinksToTheCustomerAccountOnlyWithTheCustomerManagePermission(): void
    {
        $this->assertStringContainsString(
            'href="https://admin.test/customer/12"',
            $this->render($this->card(), 'https://admin.test/customer/12')
        );
        $this->assertStringNotContainsString('admin.test/customer/12', $this->render($this->card(), ''));
    }

    public function testItHidesOrderFiguresWithoutTheSalesOrderPermission(): void
    {
        $html = $this->render($this->card(['orders' => null]), '');

        $this->assertStringNotContainsString('Lifetime spend', $html);
        $this->assertStringNotContainsString('Last order', $html);
    }

    public function testItShowsAGuestCardWithTheRespondentEmailForGuests(): void
    {
        $html = $this->render($this->card(['type' => 'guest', 'name' => '', 'group' => '', 'orders' => null, 'others' => [], 'email' => 'g@example.com']), '');

        $this->assertStringContainsString('g@example.com', $html);
        $this->assertStringNotContainsString('Customer group', $html);
        $this->assertStringNotContainsString('Other submissions', $html);
    }

    public function testItShowsTheStoredProductContextWithALinkToTheProduct(): void
    {
        $html = $this->render(null, '', [
            'name' => '<u>Lamp</u>', 'sku' => 'S<1>', 'url' => 'https://shop.test/lamp.html?a=1&b="2"', 'price' => 9.5, 'currency' => 'EUR',
        ]);

        $this->assertStringContainsString('&lt;u&gt;Lamp&lt;/u&gt;', $html);
        $this->assertStringContainsString('S&lt;1&gt;', $html);
        $this->assertStringContainsString('9.50 EUR', $html);
        $this->assertMatchesRegularExpression('#href="https://shop\.test/lamp\.html\?a=1&amp;b=(%22|&quot;)2(%22|&quot;)"#', $html);
        $this->assertStringNotContainsString('<u>', $html);
    }
}
