<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\HttpResult;
use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Api\Data\SafeUrl;

/**
 * Sends one signed webhook POST. The sender has already applied the kill switch and the URL policy
 * (`UrlPolicy::validateForSend`), but a replacement transport owns resolving the host, pinning the address
 * and the SSRF checks for the connection it opens: it must never fetch anything that `$policy` refuses.
 * Never throw for an HTTP or network failure; return an HttpResult with an error class instead.
 *
 * @api
 */
interface WebhookTransportInterface
{
    /**
     * @param array<string, string> $headers Already signed; send them unchanged
     */
    public function send(SafeUrl $url, RequestPolicy $policy, array $headers, string $body): HttpResult;
}
