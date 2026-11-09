<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * One keyset page: total_count is the number of items on this page, never a table count.
 *
 * @api
 */
interface KeysetSearchResultsInterface extends SearchResultsInterface
{
    /** True when rows follow the last item; ask again with an id filter past it. */
    public function getHasMore(): bool;

    public function setHasMore(bool $hasMore): self;
}
