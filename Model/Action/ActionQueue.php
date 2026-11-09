<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Action;

use Furan\FormBuilder\Model\Config;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Hands one action run to the furan_formbuilder.action consumer. Messages carry ids and flags only, never answers.
 * false means the caller runs the action inline.
 */
class ActionQueue
{
    public const TOPIC = 'furan_formbuilder.action';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly Config $config,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Call only after the submission is committed: a fast consumer must be able to read it. */
    public function publish(int $submissionId, string $actionKey, int $attempt, bool $isEdit): bool
    {
        if ($submissionId <= 0 || !$this->config->isAsyncActions()) {
            return false;
        }
        try {
            $this->publisher->publish(self::TOPIC, $this->json->serialize([
                'id' => $submissionId,
                'action' => $actionKey,
                'attempt' => $attempt,
                'edit' => $isEdit,
            ]));
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Form Builder could not queue an action and runs it now: ' . $e->getMessage());
            return false;
        }
    }
}
