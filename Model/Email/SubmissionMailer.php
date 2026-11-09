<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Thread\ThreadHeaders;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\TransportInterface;

/** Sends a built submission email in the submission's thread. */
class SubmissionMailer
{
    public function __construct(private readonly ThreadHeaders $threadHeaders)
    {
    }

    /**
     * @throws MailNotSentException when the transport fails
     */
    public function send(TransportInterface $transport, SubmissionInterface $submission, int $storeId): void
    {
        $id = (int)$submission->getId();
        // Unstored submissions all have id 0 and must not share one thread.
        if ($id > 0) {
            $this->threadHeaders->apply($transport, $this->threadHeaders->rootId($id, $storeId));
        }
        try {
            $transport->sendMessage();
        } catch (MailException $e) {
            throw new MailNotSentException($e->getMessage(), 0, $e);
        }
    }
}
