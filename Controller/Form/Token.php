<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\NoStoreHeaders;
use Furan\FormBuilder\Model\Condition\Today;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Prefill\TokenPayload;
use Furan\FormBuilder\Model\Spam\TokenService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class Token implements HttpGetActionInterface
{
    use NoStoreHeaders;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly TokenService $tokenService,
        private readonly TokenPayload $tokenPayload,
        private readonly Impersonation $impersonation,
        private readonly Today $today,
        private readonly Translator $translator,
        private readonly ScheduleGate $schedule,
        private readonly Config $config
    ) {
    }

    public function execute(): Json
    {
        $result = $this->noStore($this->jsonFactory->create());

        $formId = (int)$this->request->getParam('form_id');
        $storeId = (int)$this->storeManager->getStore()->getId();
        try {
            $form = $this->formRepository->getActiveById($formId, $storeId);
        } catch (NoSuchEntityException) {
            return $result->setHttpResponseCode(404)->setData(['token' => null]);
        }

        $closed = $this->schedule->refusal(
            $form,
            fn (FormInterface $raw): FormInterface => $this->translator->localize($raw, $storeId)
        );
        if ($closed !== null) {
            // No token, counters, previous answers or edit state while the form is not open.
            return $result->setData(
                [
                    'token' => null,
                    'impersonating' => $this->impersonation->isActive(),
                    'today' => $this->today->forStore($storeId),
                    'schedule' => $closed,
                ]
                + $this->tokenPayload->access($form)
            );
        }

        try {
            $token = $this->tokenService->issue($formId);
        } catch (\RuntimeException) {
            return $result->setHttpResponseCode(503)->setData(['token' => null]);
        }

        return $result->setData(
            [
                'token' => $token,
                'notBefore' => $this->config->getMinSubmitSeconds($storeId),
                'impersonating' => $this->impersonation->isActive(),
                'today' => $this->today->forStore($storeId),
            ]
            + $this->tokenPayload->build(
                $form,
                $storeId,
                max(0, (int)$this->request->getParam('product_id')),
                $this->editParam()
            )
        );
    }

    private function editParam(): string
    {
        $edit = $this->request->getParam('edit');

        return is_string($edit) ? $edit : '';
    }
}
