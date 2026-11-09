<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/** @api */
interface FormSearchResultsInterface extends KeysetSearchResultsInterface
{
    /**
     * @return FormInterface[]
     */
    public function getItems();

    /**
     * @param FormInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
