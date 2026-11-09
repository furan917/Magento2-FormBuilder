<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Ui\Component\Listing\Column;

use Furan\FormBuilder\Model\Submission\Reference as SubmissionReference;
use Magento\Ui\Component\Listing\Columns\Column;

/** The stored reference is 16 raw bytes; they must never reach the grid JSON. */
class Reference extends Column
{
    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $name = (string)$this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            $dataSource['data']['items'][$index][$name] = SubmissionReference::toString((string)($item[$name] ?? ''));
        }
        return $dataSource;
    }
}
