<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Observer;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContextFactory;

/**
 * The reCAPTCHA UI config is rendered into cached form pages, so purge them when the section changes.
 */
class RefreshFormsOnRecaptchaChange implements ObserverInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly ManagerInterface $eventManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        $connection = $this->resource->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()->from($this->resource->getTableName(FormResource::TABLE_FORM), ['form_id'])
        );
        if (!$ids) {
            return;
        }
        $context = $this->cacheContextFactory->create();
        $context->registerEntities(Form::CACHE_TAG, array_map('intval', $ids));
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
    }
}
