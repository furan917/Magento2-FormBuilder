<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Moves endpoint secrets encrypted with an older crypt key to the newest key. Never returns or logs a secret.
 */
class SecretReencrypter
{
    private const PREFIX = 'whsec_';
    private const COLUMNS = ['secret_enc', 'previous_secret_enc'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * @return array{scanned: int, reencrypted: int, skipped: int, failed: int[]}
     */
    public function run(int $batchSize): array
    {
        $stats = ['scanned' => 0, 'reencrypted' => 0, 'skipped' => 0, 'failed' => []];
        $newest = $this->newestVersion();
        $last = 0;
        do {
            $rows = $this->fetchBatch($last, $batchSize);
            foreach ($rows as $row) {
                $last = (int)$row['endpoint_id'];
                $stats['scanned']++;
                $this->process($row, $newest, $stats);
            }
        } while (count($rows) === $batchSize);

        return $stats;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{scanned: int, reencrypted: int, skipped: int, failed: int[]} $stats
     */
    private function process(array $row, string $newest, array &$stats): void
    {
        $id = (int)$row['endpoint_id'];
        $bind = [];
        $failed = false;
        foreach (self::COLUMNS as $column) {
            $stored = (string)($row[$column] ?? '');
            if ($stored === '' || $this->versionOf($stored) === $newest) {
                continue;
            }
            $plain = $this->encryptor->decrypt($stored);
            if (!str_starts_with($plain, self::PREFIX)) {
                $failed = true;
                continue;
            }
            $bind[$column] = $this->encryptor->encrypt($plain);
        }
        if ($failed) {
            $stats['failed'][] = $id;
        }
        if ($bind === []) {
            $failed || $stats['skipped']++;
            return;
        }
        // Compare-and-swap on the current secret so a concurrent rotation is never overwritten.
        $where = ['endpoint_id = ?' => $id, 'secret_enc = ?' => (string)$row['secret_enc']];
        if ($this->connection()->update($this->table(), $bind, $where) > 0) {
            $stats['reencrypted']++;
        }
    }

    private function newestVersion(): string
    {
        return $this->versionOf($this->encryptor->encrypt(''));
    }

    /** Key and cipher version (`k:c`) of a stored value. */
    private function versionOf(string $encrypted): string
    {
        return implode(':', array_slice(explode(':', $encrypted, 3), 0, 2));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBatch(int $last, int $limit): array
    {
        $sql = 'SELECT endpoint_id, secret_enc, previous_secret_enc FROM ' . $this->table()
            . ' WHERE endpoint_id > :last ORDER BY endpoint_id LIMIT ' . $limit;

        return $this->connection()->fetchAll($sql, [':last' => $last]);
    }

    private function table(): string
    {
        return $this->resource->getTableName('furan_formbuilder_webhook');
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
