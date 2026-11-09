<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\System\Message;

use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Furan\FormBuilder\Model\System\Message\RecaptchaUnavailable;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class RecaptchaUnavailableTest extends TestCase
{
    private function website(int $id, string $name): WebsiteInterface
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn((string)$id);
        $website->method('getName')->willReturn($name);
        return $website;
    }

    private function message(array $reasons): RecaptchaUnavailable
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsites')->willReturn([
            $this->website(1, 'Main <b>'),
            $this->website(2, 'Shop'),
        ]);
        $bridge = $this->createStub(ReCaptcha::class);
        $bridge->method('getUnavailableReason')->willReturnCallback(
            static fn (int $id): ?string => $reasons[$id] ?? null
        );
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($v) => htmlspecialchars((string)$v));

        return new RecaptchaUnavailable($stores, $bridge, $escaper);
    }

    public function testItShowsAnAdminNoticeWhenAWebsiteHasAFormBuilderRecaptchaTypeWhoseModuleIsDisabled(): void
    {
        $message = $this->message([2 => 'Magento_ReCaptchaVersion3Invisible']);
        $this->assertTrue($message->isDisplayed());
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertSame('furan_formbuilder_recaptcha_unavailable', $message->getIdentity());
        $text = (string)$message->getText();
        $this->assertStringContainsString('Shop', $text);
        $this->assertStringContainsString('Magento_ReCaptchaVersion3Invisible', $text);
        $this->assertStringNotContainsString('Main', $text);
    }

    public function testItEscapesWebsiteNamesAndHidesTheNoticeWhenNothingIsDisabled(): void
    {
        $this->assertFalse($this->message([])->isDisplayed());
        $text = (string)$this->message([1 => 'Magento_ReCaptchaUi'])->getText();
        $this->assertStringContainsString('Main &lt;b&gt;', $text);
    }
}
