<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\System\Message;

use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\StoreManagerInterface;

class RecaptchaUnavailable implements MessageInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ReCaptcha $reCaptcha,
        private readonly Escaper $escaper
    ) {
    }

    public function getIdentity(): string
    {
        return 'furan_formbuilder_recaptcha_unavailable';
    }

    public function isDisplayed(): bool
    {
        return $this->affected() !== [];
    }

    // @phpstan-ignore method.childReturnType (Magento renders the Phrase; the interface docblock says string)
    public function getText(): \Magento\Framework\Phrase
    {
        $parts = [];
        foreach ($this->affected() as $name => $module) {
            $parts[] = $this->escaper->escapeHtml($name) . ' (' . $this->escaper->escapeHtml($module) . ')';
        }
        return __(
            'Form Builder reCAPTCHA is set for these websites but a required module is disabled, so submissions are not protected: %1',
            implode(', ', $parts)
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * @return array<string, string> website name => disabled module
     */
    private function affected(): array
    {
        $result = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $module = $this->reCaptcha->getUnavailableReason((int)$website->getId());
            if ($module !== null) {
                $result[(string)$website->getName()] = $module;
            }
        }
        return $result;
    }
}
