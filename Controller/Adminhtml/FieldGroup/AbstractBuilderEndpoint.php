<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\FieldGroup;

use Furan\FormBuilder\Controller\FormKeyCsrf;
use Furan\FormBuilder\Model\FieldGroup\BuilderGroups;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;

/**
 * JSON endpoints used by the form builder. POST only, with the form key checked here explicitly (the default
 * validator skips it for XHR requests), and one ACL resource per action.
 */
abstract class AbstractBuilderEndpoint extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    use FormKeyCsrf;

    public function __construct(
        Context $context,
        protected readonly BuilderGroups $groups,
        private readonly JsonFactory $jsonFactory,
        private readonly Validator $formKeyValidator
    ) {
        parent::__construct($context);
    }

    private function csrfRefusal(string $message): Json
    {
        return $this->respond([403, ['success' => false, 'errors' => [$message]]]);
    }

    /**
     * @param array{int, array<string, mixed>} $outcome HTTP code and body
     */
    protected function respond(array $outcome): Json
    {
        return $this->jsonFactory->create()->setHttpResponseCode($outcome[0])->setData($outcome[1]);
    }
}
