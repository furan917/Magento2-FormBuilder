<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml;

use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * For admin downloads through FileFactory, which streams through the shared response: these headers must be set
 * on it before FileFactory sends.
 */
trait SecureDownload
{
    private function secureDownloadHeaders(): void
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHeader('Content-Security-Policy', 'sandbox', true)
            ->setHeader('Cache-Control', 'private, no-store', true);
    }
}
