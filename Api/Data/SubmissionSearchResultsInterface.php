<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/** @api */
interface SubmissionSearchResultsInterface extends KeysetSearchResultsInterface
{
    /**
     * @return SubmissionInterface[]
     */
    public function getItems();

    /**
     * @param SubmissionInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
