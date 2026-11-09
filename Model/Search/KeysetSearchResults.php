<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Search;

use Furan\FormBuilder\Api\Data\KeysetSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

abstract class KeysetSearchResults extends SearchResults implements KeysetSearchResultsInterface
{
    private const HAS_MORE = 'has_more';

    public function getHasMore(): bool
    {
        return (bool)$this->_get(self::HAS_MORE);
    }

    public function setHasMore(bool $hasMore): KeysetSearchResultsInterface
    {
        return $this->setData(self::HAS_MORE, $hasMore);
    }
}
