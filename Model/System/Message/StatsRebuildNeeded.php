<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\System\Message;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;

class StatsRebuildNeeded implements MessageInterface
{
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ResourceConnection $resource,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper
    ) {
    }

    public function getIdentity(): string
    {
        return 'furan_formbuilder_stats_rebuild_needed';
    }

    public function isDisplayed(): bool
    {
        return $this->authorization->isAllowed('Furan_FormBuilder::analytics') && (bool)$this->resource
            ->getConnection()->fetchOne(
                'SELECT 1 FROM ' . $this->resource->getTableName('furan_formbuilder_stat_state')
                . ' WHERE needs_rebuild = 1 AND rebuild_requested = 0 AND rebuilding = 0 LIMIT 1'
            );
    }

    // @phpstan-ignore method.childReturnType (Magento renders the Phrase; the interface docblock says string)
    public function getText(): \Magento\Framework\Phrase
    {
        return __(
            'Form Builder statistics do not include earlier submissions. Open the %1, then the analytics page of a form, and choose %2.',
            '<a href="' . $this->escaper->escapeUrl($this->urlBuilder->getUrl('furanform/form/index')) . '">'
                . $this->escaper->escapeHtml(__('forms list')) . '</a>',
            $this->escaper->escapeHtml(__('Rebuild now'))
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
