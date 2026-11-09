<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller;

use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * For CsrfAwareActionInterface controllers with a $formKeyValidator.
 */
trait FormKeyCsrf
{
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Explicit: the default CsrfValidator skips the form-key check for XHR requests.
        return $this->formKeyValidator->validate($request);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException(
            $this->csrfRefusal((string)__('Your session has expired. Please refresh the page and try again.'))
        );
    }

    abstract private function csrfRefusal(string $message): ResultInterface|ResponseInterface;
}
