<?php
declare(strict_types=1);

namespace Furan\FormBuilder\ViewModel\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\ResourceModel\Submission\Grid\Collection;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class GridNotice implements ArgumentInterface
{
    public function __construct(
        private readonly SubmissionResource $submissions,
        private readonly ResolverInterface $localeResolver
    ) {
    }

    public function formattedCap(): string
    {
        return (string)(new \NumberFormatter($this->localeResolver->getLocale(), \NumberFormatter::DECIMAL))
            ->format(Collection::CAP);
    }

    /** Rendered once; filters arrive over AJAX, and a filtered count is never above the unfiltered one. */
    public function isVisible(): bool
    {
        return $this->submissions->countUpTo(Collection::CAP + 1) > Collection::CAP;
    }
}
