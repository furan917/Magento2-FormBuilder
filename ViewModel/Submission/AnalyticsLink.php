<?php
declare(strict_types=1);

namespace Furan\FormBuilder\ViewModel\Submission;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/** Analytics link on a submissions grid opened for one form, the way in for roles that cannot open the forms grid. */
class AnalyticsLink implements ArgumentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function url(): string
    {
        $formId = $this->request->getParam('form_id');
        if (!is_string($formId) || !ctype_digit($formId) || (int)$formId <= 0
            || !$this->authorization->isAllowed('Furan_FormBuilder::analytics')
        ) {
            return '';
        }
        return $this->urlBuilder->getUrl('furanform/analytics/index', ['form_id' => (int)$formId]);
    }
}
