<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Http\ClientIp;
use Furan\FormBuilder\Model\Upload\FilesNormalizer;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Store\Model\StoreManagerInterface;

/** The only place an HTTP request is turned into a SubmissionInput. */
class SubmissionInputFactory
{
    private const HEADER_CAP = 512;

    public function __construct(
        private readonly Session $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ClientIp $clientIp,
        private readonly FilesNormalizer $filesNormalizer,
        private readonly Impersonation $impersonation
    ) {
    }

    public function fromHttp(Http $request): SubmissionInput
    {
        $loggedIn = (bool)$this->customerSession->isLoggedIn();
        $fields = $request->getParam('fields', []);

        return new SubmissionInput(
            is_array($fields) ? $fields : [],
            $this->filesNormalizer->normalize($request->getFiles('files')),
            $this->text($request->getParam('ffb_token')),
            $this->text($request->getParam('ffb_edit')),
            (int)$this->scalar($request->getParam('ffb_product_id')),
            $this->honeypot($request->getParam('ffb_website')),
            $this->text($request->getParam('g-recaptcha-response')),
            max(0, (int)$request->getServer('CONTENT_LENGTH')),
            $this->clientIp->address(),
            $this->capped($request->getServer('HTTP_USER_AGENT')),
            $this->capped($request->getServer('HTTP_REFERER')),
            $loggedIn ? (int)$this->customerSession->getCustomerId() : 0,
            $loggedIn ? (int)$this->customerSession->getCustomerGroupId() : 0,
            $loggedIn ? (string)$this->customerSession->getCustomerData()->getEmail() : '',
            $this->impersonation->isActive(),
            (int)$this->storeManager->getStore()->getId()
        );
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /** Any non-string value in the trap field still counts as filled. */
    private function honeypot(mixed $value): string
    {
        return $value === null ? '' : (is_string($value) ? $value : '1');
    }

    private function capped(mixed $value): string
    {
        return substr($this->text($value), 0, self::HEADER_CAP);
    }
}
