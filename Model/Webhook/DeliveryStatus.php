<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** Delivery status (smallint column) and attempt error classes (varchar(32) columns). */
class DeliveryStatus
{
    public const PENDING = 0;
    public const DELIVERING = 1;
    public const SUCCEEDED = 2;
    public const FAILED = 3;
    public const BLOCKED = 4;
    public const CANCELLED = 5;

    public const STATUSES = [
        self::PENDING, self::DELIVERING, self::SUCCEEDED, self::FAILED, self::BLOCKED, self::CANCELLED,
    ];

    /** Statuses a delivery never leaves; only these are purged by age. */
    public const FINAL = [self::SUCCEEDED, self::FAILED, self::BLOCKED, self::CANCELLED];

    public const ERROR_DNS = 'dns';
    public const ERROR_BLOCKED = 'blocked';
    public const ERROR_CONNECT = 'connect';
    public const ERROR_TLS = 'tls';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_RESPONSE_TOO_LARGE = 'response_too_large';
    public const ERROR_HTTP = 'http';
    public const ERROR_UNSUPPORTED = 'unsupported';
    public const ERROR_PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const ERROR_SECRET_UNAVAILABLE = 'secret_unavailable';
    public const ERROR_ENDPOINT_GONE = 'endpoint_gone';
    public const ERROR_EXPIRED = 'expired';
    public const ERROR_VETOED = 'vetoed';
    public const ERROR_OBSERVER = 'observer';
    public const ERROR_NOT_SUBSCRIBED = 'not_subscribed';
    public const ERROR_ENDPOINT_INACTIVE = 'endpoint_inactive';

    public const ERRORS = [
        self::ERROR_DNS, self::ERROR_BLOCKED, self::ERROR_CONNECT, self::ERROR_TLS, self::ERROR_TIMEOUT,
        self::ERROR_RESPONSE_TOO_LARGE, self::ERROR_HTTP, self::ERROR_UNSUPPORTED, self::ERROR_PAYLOAD_TOO_LARGE,
        self::ERROR_SECRET_UNAVAILABLE, self::ERROR_ENDPOINT_GONE, self::ERROR_EXPIRED, self::ERROR_VETOED,
        self::ERROR_OBSERVER, self::ERROR_NOT_SUBSCRIBED, self::ERROR_ENDPOINT_INACTIVE,
    ];

    public static function label(int $status): string
    {
        return match ($status) {
            self::PENDING => (string)__('Pending'),
            self::DELIVERING => (string)__('Sending'),
            self::SUCCEEDED => (string)__('Delivered'),
            self::FAILED => (string)__('Failed'),
            self::BLOCKED => (string)__('Blocked'),
            self::CANCELLED => (string)__('Cancelled'),
            default => (string)__('Unknown'),
        };
    }

    public static function errorLabel(string $code): string
    {
        return match ($code) {
            self::ERROR_DNS => (string)__('The host name could not be found. Check the URL.'),
            self::ERROR_BLOCKED => (string)__('The address is not allowed. Use a public address, or ask your administrator.'),
            self::ERROR_CONNECT => (string)__('The connection was refused or dropped. Check that the receiver is running.'),
            self::ERROR_TLS => (string)__('The secure connection failed. Check the receiver certificate.'),
            self::ERROR_TIMEOUT => (string)__('The receiver took too long to answer. Make it respond faster.'),
            self::ERROR_RESPONSE_TOO_LARGE => (string)__('The response was too large. Make the receiver answer briefly.'),
            self::ERROR_HTTP => (string)__('The receiver answered with an error status. See the HTTP status.'),
            self::ERROR_UNSUPPORTED => (string)__('The URL is not supported. Use http or https.'),
            self::ERROR_PAYLOAD_TOO_LARGE => (string)__('The payload was too large to send. Send fewer fields.'),
            self::ERROR_SECRET_UNAVAILABLE => (string)__('The signing secret could not be read. Roll the secret or run the reencrypt command.'),
            self::ERROR_ENDPOINT_GONE => (string)__('The endpoint was deleted before this was sent.'),
            self::ERROR_EXPIRED => (string)__('Not sent in time. The delivery expired.'),
            self::ERROR_VETOED => (string)__('A custom extension stopped this delivery.'),
            self::ERROR_OBSERVER => (string)__('A custom extension failed while this was prepared.'),
            self::ERROR_NOT_SUBSCRIBED => (string)__('The endpoint no longer listens to this event.'),
            self::ERROR_ENDPOINT_INACTIVE => (string)__('The endpoint was switched off. Turn it on to send again.'),
            default => (string)__('Unknown error'),
        };
    }

    /** Same wording as the endpoint edit page. */
    public static function eventLabel(string $event): string
    {
        return match ($event) {
            'submission.created' => (string)__('A submission is received'),
            'submission.updated' => (string)__('A submission is edited'),
            'submission.status_changed' => (string)__('A submission status changes'),
            Outbox::TEST_EVENT => (string)__('Test event'),
            default => $event,
        };
    }
}
