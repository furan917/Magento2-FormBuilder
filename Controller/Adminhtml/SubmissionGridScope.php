<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml;

/**
 * Keeps a grid opened for one form scoped to it after a mass action: its remembered filters still apply.
 */
trait SubmissionGridScope
{
    /** @return array<string, int> */
    private function gridParams(): array
    {
        $formId = $this->getRequest()->getParam('form_id');
        return is_string($formId) && ctype_digit($formId) && (int)$formId > 0 ? ['form_id' => (int)$formId] : [];
    }
}
