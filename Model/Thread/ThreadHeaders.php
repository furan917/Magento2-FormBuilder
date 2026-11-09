<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/** One conversation per submission via a virtual root id. Message-ID stays unique: clients hide reused ids. */
class ThreadHeaders
{
    private const LABEL = 'furan_formbuilder_thread_root';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /** Bare id (no angle brackets). Keyed with the FIRST crypt key so it survives key rotation. */
    public function rootId(int $submissionId, ?int $storeId): string
    {
        $host = $this->host($storeId);
        $keys = preg_split('/\s+/', trim((string)$this->deploymentConfig->get('crypt/key')), -1, PREG_SPLIT_NO_EMPTY);
        $hash = $keys
            ? hash_hmac('sha256', (string)$submissionId, hash_hmac('sha256', self::LABEL, (string)$keys[0]))
            : hash('sha256', self::LABEL . '|' . $submissionId . '|' . $host);

        return 'ffb-' . $submissionId . '-' . substr($hash, 0, 16) . '@' . $host;
    }

    /** Call between getTransport() and sendMessage(). Laminas-based messages (Magento < 2.4.8) are left as they are. */
    public function apply(TransportInterface $transport, string $rootId): void
    {
        $message = $transport->getMessage();
        if (!is_object($message) || !method_exists($message, 'getSymfonyMessage')) {
            return;
        }
        $headers = $message->getSymfonyMessage()->getHeaders();
        foreach (['In-Reply-To', 'References'] as $name) {
            $headers->remove($name);
            $headers->addIdHeader($name, [$rootId]);
        }
    }

    private function host(?int $storeId): string
    {
        try {
            /** @var Store $store */
            $store = $this->storeManager->getStore($storeId);
            $host = strtolower((string)parse_url($store->getBaseUrl(), PHP_URL_HOST));
        } catch (\Throwable) {
            $host = '';
        }

        return preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/D', $host) === 1 ? $host : 'localhost';
    }
}
