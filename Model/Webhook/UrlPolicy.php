<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Api\Data\SafeUrl;
use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Http\UrlNormalizer;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;

/**
 * Webhook URL policy. Every switch comes from env.php (furan_formbuilder/webhooks/*), never from admin configuration.
 */
class UrlPolicy
{
    private const PREFIX = 'furan_formbuilder/webhooks/';

    private ?RequestPolicy $policy = null;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly State $state,
        private readonly UrlNormalizer $normalizer,
        private readonly SafeHttpClient $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->deploymentConfig->get(self::PREFIX . 'enabled', true) !== false;
    }

    public function policy(): RequestPolicy
    {
        return $this->policy ??= $this->build();
    }

    /**
     * @throws UnsafeUrlException
     */
    public function validateForSave(string $url): SafeUrl
    {
        $policy = $this->policy();
        $safeUrl = $this->normalizer->normalize($url, $policy);
        return match ($this->client->preflight($safeUrl, $policy)) {
            SafeHttpClient::PREFLIGHT_OK => $safeUrl,
            SafeHttpClient::PREFLIGHT_UNRESOLVED => throw new UnsafeUrlException('host_unresolved'),
            default => throw new UnsafeUrlException('address_not_public'),
        };
    }

    /**
     * @throws UnsafeUrlException
     */
    public function validateForSend(string $storedUrl): SafeUrl
    {
        return $this->normalizer->normalize($storedUrl, $this->policy());
    }

    private function build(): RequestPolicy
    {
        $developer = $this->state->getMode() === State::MODE_DEVELOPER;
        $allowHttp = $this->flag('allow_http');
        $allowCidrs = $this->list('allow_cidrs', fn (array $v): RequestPolicy => new RequestPolicy(allowCidrs: $v));
        if ($allowHttp || $allowCidrs !== []) {
            if ($developer) {
                $this->logger->critical(
                    'Furan_FormBuilder: webhook dev flags allow_http and allow_cidrs are active. '
                    . 'Never use them outside a development machine.'
                );
            } else {
                $this->logger->warning(
                    'Furan_FormBuilder: webhook dev flags allow_http and allow_cidrs are ignored '
                    . 'because the application is not in developer mode.'
                );
                $allowHttp = false;
                $allowCidrs = [];
            }
        }
        return new RequestPolicy(
            $allowHttp,
            $this->list('ports', fn (array $v): RequestPolicy => new RequestPolicy(ports: $v), [443]),
            $this->list('ip_literal_hosts', fn (array $v): RequestPolicy => new RequestPolicy(ipLiteralHosts: $v)),
            $this->list('host_allowlist', fn (array $v): RequestPolicy => new RequestPolicy(hostAllowlist: $v)),
            $allowCidrs,
            $this->flag('nat64_decode'),
            $this->milliseconds('connect_timeout_ms', 3000, 1000, 30000),
            $this->milliseconds('timeout_ms', 10000, 5000, 30000)
        );
    }

    private function flag(string $key): bool
    {
        $value = $this->deploymentConfig->get(self::PREFIX . $key, false);
        if (is_bool($value)) {
            return $value;
        }
        $this->malformed($key);
        return false;
    }

    private function milliseconds(string $key, int $default, int $min, int $max): int
    {
        $value = $this->deploymentConfig->get(self::PREFIX . $key);
        if ($value === null) {
            return $default;
        }
        if (!is_int($value)) {
            $this->malformed($key);
            return $default;
        }
        return max($min, min($max, $value));
    }

    /**
     * @param callable(array<int,mixed>):RequestPolicy $probe builds a policy from only this list
     * @param mixed[] $default
     * @return mixed[]
     */
    private function list(string $key, callable $probe, array $default = []): array
    {
        $value = $this->deploymentConfig->get(self::PREFIX . $key);
        if ($value === null) {
            return $default;
        }
        if (is_array($value) && array_is_list($value)) {
            $accepted = match ($key) {
                'ports' => $probe($value)->ports,
                'ip_literal_hosts' => $probe($value)->ipLiteralHosts,
                'host_allowlist' => $probe($value)->hostAllowlist,
                default => $probe($value)->allowCidrs,
            };
            if (count($accepted) === count(array_unique($value, SORT_REGULAR))) {
                return $value;
            }
        }
        $this->malformed($key);
        return $default;
    }

    private function malformed(string $key): void
    {
        $this->logger->warning('Furan_FormBuilder: malformed env.php value ignored: ' . self::PREFIX . $key);
    }
}
