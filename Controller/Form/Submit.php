<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\FormKeyCsrf;
use Furan\FormBuilder\Controller\NoStoreHeaders;
use Furan\FormBuilder\Model\Submission\Processor;
use Furan\FormBuilder\Model\Submission\SubmissionInputFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Submit implements HttpPostActionInterface, CsrfAwareActionInterface
{
    use FormKeyCsrf;
    use NoStoreHeaders;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly Http $request,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Processor $processor,
        private readonly SubmissionInputFactory $inputFactory,
        private readonly Validator $formKeyValidator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $formId = (int)$this->request->getParam('form_id');
        try {
            $form = $this->formRepository->getActiveById($formId, (int)$this->storeManager->getStore()->getId());
        } catch (NoSuchEntityException) {
            return $this->respond(404, (string)__('This form is no longer available.'));
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder submit lookup failed: ' . $e->getMessage(), ['exception' => $e]);
            return $this->respond(500, (string)__('Something went wrong. Please try again later.'));
        }

        $result = $this->processor->process($form, $this->inputFactory->fromHttp($this->request));

        return $this->respond(
            $result->getHttpCode(),
            $result->getMessage(),
            $result->isSuccess(),
            $result->getErrors(),
            $result->getRedirect(),
            $result->getReference()
        );
    }

    private function csrfRefusal(string $message): Json
    {
        return $this->respond(403, $message);
    }

    /**
     * @param array<string, string> $errors
     */
    private function respond(
        int $code,
        string $message,
        bool $success = false,
        array $errors = [],
        ?string $redirect = null,
        ?string $reference = null
    ): Json {
        $json = $this->noStore($this->jsonFactory->create());

        $data = [
            'success' => $success,
            'message' => $message,
            'errors' => $errors,
            'redirect' => $redirect,
        ];
        if ($reference !== null) {
            $data['reference'] = $reference;
        }

        return $json->setHttpResponseCode($code)->setData($data);
    }
}
