<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Privacy\Exporter;
use Furan\FormBuilder\Model\Throttle\Counter;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/** Zip download of the customer's own linked submissions, one export per cooldown. */
class Export implements AccountInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    use AccountCsrf;

    private const COOLDOWN_SECONDS = 300;
    private const COOLDOWN_KEY = 'ffb_export_';

    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly Session $session,
        private readonly Http $request,
        private readonly Exporter $exporter,
        private readonly FileFactory $fileFactory,
        private readonly Counter $counter,
        private readonly Validator $formKeyValidator,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger,
        private readonly Impersonation $impersonation,
        private readonly HttpResponse $response
    ) {
    }

    /** @return ResponseInterface|Redirect|Forward */
    public function execute()
    {
        $customerId = (int)$this->session->getCustomerId();
        if ($customerId <= 0) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        if ($this->impersonation->isActive()) {
            $this->messageManager->addErrorMessage($this->impersonation->accountRefusal());
            return $this->toList();
        }
        // Counted before the export runs, so failed attempts count too.
        if ($this->counter->hit(self::COOLDOWN_KEY . $customerId, self::COOLDOWN_SECONDS) > 1) {
            $this->messageManager->addErrorMessage(
                (string)__('You requested an export a moment ago. Please try again in a few minutes.')
            );
            return $this->toList();
        }

        try {
            $path = $this->exporter->exportCustomer($customerId);
        } catch (\Throwable $e) {
            $this->logger->error('Furan_FormBuilder: customer export failed.', ['exception' => $e::class]);
            $this->messageManager->addErrorMessage((string)__('Your data could not be exported.'));
            return $this->toList();
        }

        // FileFactory sends through this shared response, so the headers must be on it first.
        $this->response
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHeader('Content-Security-Policy', 'sandbox', true);
        // 'rm' => true deletes the zip from var/ after sending it.
        return $this->fileFactory->create(
            sprintf('my-form-submissions-%s.zip', gmdate('Ymd-His')),
            ['type' => 'filename', 'value' => $path, 'rm' => true],
            DirectoryList::VAR_DIR,
            'application/zip'
        );
    }

    private function toList(): Redirect
    {
        return $this->redirectFactory->create()->setPath('furanform/account/submissions');
    }
}
