<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

/**
 * Thin seam over ext-curl so the transport can be unit tested without a network.
 * Handles are never closed explicitly: curl_close() is a no-op since PHP 8.0 and deprecated in 8.5.
 */
class CurlSession
{
    public function open(): ?object
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- thin seam over ext-curl so the client can be unit tested
        $handle = curl_init();
        return $handle === false ? null : $handle;
    }

    public function setOption(object $handle, int $option, mixed $value): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- thin seam over ext-curl so the client can be unit tested
        return $handle instanceof \CurlHandle && curl_setopt($handle, $option, $value);
    }

    public function execute(object $handle): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- thin seam over ext-curl so the client can be unit tested
        return $handle instanceof \CurlHandle && curl_exec($handle) !== false;
    }

    public function info(object $handle, int $option): mixed
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- thin seam over ext-curl so the client can be unit tested
        return $handle instanceof \CurlHandle ? curl_getinfo($handle, $option) : null;
    }

    public function errorNumber(object $handle): int
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- thin seam over ext-curl so the client can be unit tested
        return $handle instanceof \CurlHandle ? curl_errno($handle) : 0;
    }
}
