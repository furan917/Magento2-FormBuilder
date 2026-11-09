<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Mail;

use Furan\FormBuilder\Model\Config;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Hands claim and customer reply emails to the furan_formbuilder.mail consumer (AMQP when configured, else
 * the MySQL queue). Messages carry ids only. false means the caller sends inline. Submission actions use
 * Action\ActionQueue.
 */
class MailQueue
{
    public const TOPIC = 'furan_formbuilder.mail';
    public const KIND_CLAIM = 'claim';
    public const KIND_REPLY = 'reply';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly Config $config,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /** $storeId is the store resolved at login, so the link never points at the admin scope. */
    public function publishClaim(int $customerId, int $storeId): bool
    {
        return $this->publish(['kind' => self::KIND_CLAIM, 'id' => $customerId, 'store' => $storeId]);
    }

    /** The consumer resolves the store again: the submission's store view may be deleted meanwhile. */
    public function publishReply(int $messageId): bool
    {
        return $this->publish(['kind' => self::KIND_REPLY, 'id' => $messageId]);
    }

    /**
     * @param array{kind: string, id: int, store?: int} $message
     */
    private function publish(array $message): bool
    {
        if ($message['id'] <= 0 || !$this->config->isAsyncMail()) {
            return false;
        }
        try {
            $this->publisher->publish(self::TOPIC, $this->json->serialize($message));
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Form Builder could not queue an email and sends it now: ' . $e->getMessage());
            return false;
        }
    }
}
