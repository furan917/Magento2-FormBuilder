<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;

/** Writes one HTTP attempt row, and the opt-in response excerpt in its own table. Takes primitives only. */
class AttemptRecorder
{
    public const TABLE = 'furan_formbuilder_webhook_attempt';
    public const DEBUG_TABLE = 'furan_formbuilder_webhook_attempt_debug';
    public const EXCERPT_BYTES = 1024;
    private const LOCATION_LENGTH = 255;
    private const ELLIPSIS = '…';
    private const MORE = '/…';

    /** @var callable */
    private $clock;

    public function __construct(private readonly ResourceConnection $resource, ?callable $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function record(
        int $deliveryId,
        string $startedAt,
        int $durationMs,
        ?int $statusCode,
        ?string $errorClass,
        ?int $errno,
        ?string $connectedIp,
        ?string $location,
        ?int $retryAfter,
        ?string $debugExcerpt = null
    ): int {
        /** @var Mysql $connection lastInsertId() is not on AdapterInterface */
        $connection = $this->resource->getConnection();
        $connection->insert($this->resource->getTableName(self::TABLE), [
            'delivery_id' => $deliveryId,
            'started_at' => $startedAt,
            'duration_ms' => max(0, $durationMs),
            'status_code' => $statusCode,
            'error_class' => $errorClass,
            'errno' => $errno,
            'connected_ip' => $connectedIp,
            'location' => $location === null ? null : $this->redirectOrigin($location),
            'retry_after' => $retryAfter,
        ]);
        $attemptId = (int)$connection->lastInsertId($this->resource->getTableName(self::TABLE));

        if ($debugExcerpt !== null) {
            $connection->insert($this->resource->getTableName(self::DEBUG_TABLE), [
                'attempt_id' => $attemptId,
                'excerpt' => mb_strcut(mb_scrub($this->stripControl($debugExcerpt), 'UTF-8'), 0, self::EXCERPT_BYTES, 'UTF-8'),
                'created_at' => gmdate('Y-m-d H:i:s', (int)($this->clock)()),
            ]);
        }

        return $attemptId;
    }

    /** Scheme, host and port only: redirect paths and queries can carry tokens, and raw bytes can break the insert. */
    private function redirectOrigin(string $location): string
    {
        $parts = parse_url((string)preg_replace('/[^\x21-\x7E]/', '', $location));
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return self::ELLIPSIS;
        }
        $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $more = !in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['query']) || isset($parts['fragment']);
        $room = self::LOCATION_LENGTH - strlen(self::MORE);
        if (!$more && strlen($origin) <= self::LOCATION_LENGTH) {
            return $origin;
        }

        return substr($origin, 0, $room) . self::MORE;
    }

    private function stripControl(string $value): string
    {
        return (string)preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }
}
