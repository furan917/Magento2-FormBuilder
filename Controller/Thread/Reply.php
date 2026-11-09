<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Thread;

use Furan\FormBuilder\Controller\FormKeyCsrf;
use Furan\FormBuilder\Model\Thread\CustomerReply;
use Furan\FormBuilder\Model\Thread\PrivateResponse;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Furan\FormBuilder\Model\Thread\ThreadGrants;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Guest reply. Needs the session grant from View plus the form key; a token alone is never accepted here. */
class Reply implements HttpPostActionInterface, CsrfAwareActionInterface
{
    use FormKeyCsrf;

    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly Http $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly ThreadAccess $threadAccess,
        private readonly ThreadGrants $grants,
        private readonly CustomerReply $reply,
        private readonly Validator $formKeyValidator,
        private readonly ManagerInterface $messageManager
    ) {
    }

    /** @return Redirect */
    public function execute()
    {
        $id = $this->requestedId();
        $storeId = (int)$this->storeManager->getStore()->getId();
        $context = $this->threadAccess->load($id, $storeId);
        if ($context === null || !$this->grants->allows($id, $context['version'], $context['fingerprints'])) {
            return $this->redirect([]);
        }

        $outcome = $this->reply->postAsGuest(
            $context['submission'],
            $context['form'],
            (string)$this->request->getParam('body'),
            $context['recipient'],
            $storeId
        );
        if ($outcome->isSent()) {
            $this->messageManager->addSuccessMessage($outcome->message);
        } else {
            $this->messageManager->addErrorMessage($outcome->message);
        }

        return $this->redirect(['_query' => ['id' => $id]]);
    }

    private function csrfRefusal(string $message): Redirect
    {
        $this->messageManager->addErrorMessage($message);
        $id = $this->requestedId();
        return $this->redirect($id > 0 ? ['_query' => ['id' => $id]] : []);
    }

    /** @param array<string, mixed> $params */
    private function redirect(array $params): Redirect
    {
        $redirect = $this->redirectFactory->create()->setPath('furanform/thread/view', $params + ['_scope_to_url' => true]);
        PrivateResponse::apply($redirect);
        return $redirect;
    }

    /** Digits only: "12abc" or id[]= must not resolve to a submission. */
    private function requestedId(): int
    {
        $raw = $this->request->getParam('id');
        return is_string($raw) && ctype_digit($raw) && strlen($raw) <= 10 ? (int)$raw : 0;
    }
}
