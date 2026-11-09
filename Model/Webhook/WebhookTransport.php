<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Api\WebhookTransportInterface;
use Furan\FormBuilder\Api\Data\HttpResult;
use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Api\Data\SafeUrl;

/** The default transport: the hardened SafeHttpClient. */
class WebhookTransport implements WebhookTransportInterface
{
    public function __construct(private readonly SafeHttpClient $client)
    {
    }

    /**
     * @param array<string,string> $headers
     */
    public function send(SafeUrl $url, RequestPolicy $policy, array $headers, string $body): HttpResult
    {
        return $this->client->request('POST', $url, $policy, $headers, $body);
    }
}
