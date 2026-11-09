<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/**
 * Everything a submission needs from its transport, so the submit pipeline never reads an HTTP request.
 * `userAgent` and `referrer` are for spam checks only: never store them, log them or send them anywhere.
 *
 * @api
 */
// phpcs:ignore Magento2.PHP.FinalImplementation -- immutable value object, not meant for plugins or proxies
final class SubmissionInput
{
    /**
     * @param array<string, mixed> $fields posted answers by field name
     * @param array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     *     already normalised uploads by field name
     * @param int $contentLength request body bytes, 0 when unknown (chunked)
     * @param string $clientIp address after the trusted proxy rules, '' when unknown
     * @param int $customerId 0 for a guest
     * @param int $customerGroupId 0 for a guest
     * @param bool $impersonated an admin is browsing as the customer
     */
    public function __construct(
        public readonly array $fields = [],
        public readonly array $files = [],
        public readonly string $formToken = '',
        public readonly string $editToken = '',
        public readonly int $productId = 0,
        public readonly string $honeypot = '',
        public readonly string $recaptchaResponse = '',
        public readonly int $contentLength = 0,
        public readonly string $clientIp = '',
        public readonly string $userAgent = '',
        public readonly string $referrer = '',
        public readonly int $customerId = 0,
        public readonly int $customerGroupId = 0,
        public readonly string $customerEmail = '',
        public readonly bool $impersonated = false,
        public readonly int $storeId = 0
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->customerId > 0;
    }
}
