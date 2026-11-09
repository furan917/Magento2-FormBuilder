<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Claim;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Sends the signed "claim your earlier submissions" link; used inline by the login observer or by the mail consumer. */
class ClaimMailer
{
    public function __construct(
        private readonly Config $config,
        private readonly ClaimToken $claimToken,
        private readonly EmailHasher $emailHasher,
        private readonly TransportBuilderFactory $transportBuilderFactory,
        private readonly StateInterface $inlineTranslation,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function send(int $customerId, string $email, string $name, int $storeId): void
    {
        $fingerprint = $this->emailHasher->hash($email);
        if ($customerId < 1 || $fingerprint === null) {
            return;
        }
        $token = $this->claimToken->issue($customerId, $fingerprint['hash']);
        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilderFactory->create()
                ->setTemplateIdentifier($this->config->getEmailTemplate('claim', $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    // The name lands in a {{trans}} parameter: braces would be read as directives.
                    'customer_name' => MessageRenderer::braces($name),
                    'claim_url' => MessageRenderer::braces($this->url->getUrl(
                        'furanform/account/claim',
                        [
                            '_query' => ['token' => $token],
                            '_nosid' => true,
                            '_scope' => $this->storeManager->getStore($storeId),
                            '_scope_to_url' => true,
                        ]
                    )),
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
                ->addTo($email, $name)
                ->getTransport()
                ->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
