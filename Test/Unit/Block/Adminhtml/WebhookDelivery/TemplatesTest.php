<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\WebhookDelivery;

use PHPUnit\Framework\TestCase;

class TemplatesTest extends TestCase
{
    private function source(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/../../../../../view/adminhtml/templates/webhook_delivery/' . $name . '.phtml');
    }

    public function testItIdentifiesEachRowByARowHeader(): void
    {
        $this->assertStringContainsString('<th scope="row"', $this->source('index'));
        $this->assertStringContainsString('<th scope="row"', $this->source('view'));
    }

    public function testItDoesNotLabelThePreformattedExcerpt(): void
    {
        $this->assertDoesNotMatchRegularExpression('/<pre[^>]*aria-labelledby/', $this->source('view'));
    }

    public function testItShowsErrorsInPlainWordsWithTheCodeAsSecondaryText(): void
    {
        foreach (['index', 'view'] as $template) {
            $this->assertStringContainsString('DeliveryStatus::errorLabel(', $this->source($template));
        }
    }

    public function testItLetsTheTablesScrollInsteadOfCrushingThem(): void
    {
        $css = (string)file_get_contents(__DIR__ . '/../../../../../view/adminhtml/web/css/webhooks.css');

        $this->assertMatchesRegularExpression('/\.ffb-admin-delivery-log\s*\{\s*min-width:\s*840px/', $css);
        $this->assertMatchesRegularExpression('/\.ffb-admin-delivery-attempts\s*\{\s*min-width:\s*760px/', $css);
        $this->assertStringContainsString('ffb-admin-delivery-log', $this->source('index'));
        $this->assertStringContainsString('ffb-admin-delivery-attempts', $this->source('view'));
    }
}
