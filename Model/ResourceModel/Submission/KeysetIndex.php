<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel\Submission;

use Magento\Framework\App\ResourceConnection;

/**
 * Index hints for keyset walks over one form's submissions. Measured on MySQL 8: for
 * "form_id = ? AND submission_id > ? ORDER BY submission_id LIMIT 500" the optimizer often picks (form_id, is_read)
 * or (form_id, created_at) and filesorts every remaining row of the form, which makes a full walk quadratic.
 */
class KeysetIndex
{
    private const TABLE = 'furan_formbuilder_submission';
    private const COLUMNS = ['form_id', 'submission_id'];

    private ?string $formHint = null;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** " FORCE INDEX (`…`)" for the (form_id, submission_id) index, looked up by columns (names differ with a table prefix). */
    public function forForm(): string
    {
        if ($this->formHint === null) {
            $this->formHint = '';
            $connection = $this->resource->getConnection();
            foreach ($connection->getIndexList($this->resource->getTableName(self::TABLE)) as $name => $index) {
                if (array_map(strtolower(...), (array)($index['COLUMNS_LIST'] ?? [])) === self::COLUMNS) {
                    $this->formHint = ' FORCE INDEX (' . $connection->quoteIdentifier((string)$name) . ')';
                    break;
                }
            }
        }
        return $this->formHint;
    }

    /** For walks over the whole table by id. */
    public function forPrimary(): string
    {
        return ' FORCE INDEX (PRIMARY)';
    }
}
