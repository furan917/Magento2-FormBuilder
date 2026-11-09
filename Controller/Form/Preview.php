<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Form;
use Furan\FormBuilder\Controller\NoStoreHeaders;
use Furan\FormBuilder\Model\Form\PreviewToken;
use Furan\FormBuilder\Model\I18n\Translator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin preview of a form, active or not, behind a signed token. Submitting stays refused for inactive forms
 * because the token, draft and submit endpoints only serve active ones.
 */
class Preview implements HttpGetActionInterface
{
    use NoStoreHeaders;

    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly RequestInterface $request,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ForwardFactory $forwardFactory,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
        private readonly PreviewToken $previewToken
    ) {
    }

    public function execute(): Page|Forward
    {
        $id = $this->request->getParam('id');
        $token = $this->request->getParam('token');
        if (!is_string($id) || !ctype_digit($id) || !is_string($token) || !$this->previewToken->verify($token, (int)$id)) {
            return $this->noroute();
        }
        try {
            $form = $this->formRepository->getById((int)$id);
        } catch (NoSuchEntityException) {
            return $this->noroute();
        }
        $form = $this->translator->localize($form, (int)$this->storeManager->getStore()->getId());

        $page = $this->pageFactory->create();
        $layout = $page->getLayout();
        /** @var Form|false $block */
        $block = $layout->getBlock('furanform.form');
        if (!$block) {
            $this->logger->error('Furan_FormBuilder: block furanform.form is missing from the layout.');
            return $this->noroute();
        }
        $block->setForm($form);
        $title = trim((string)($form->getSettings()['page_title'] ?? ''));
        $block->setData('page_heading', $title !== '' ? $title : $form->getTitle());
        $notice = $layout->getBlock('furanform.preview.notice');
        if ($notice instanceof DataObject) {
            $notice->setData('inactive', !$form->isActive());
        }
        $config = $page->getConfig();
        $config->getTitle()->set($title !== '' ? $title : $form->getTitle());
        $config->addBodyClass('furanform-view');
        $config->setRobots('NOINDEX,NOFOLLOW');

        return $this->noStore($page);
    }

    private function noroute(): Forward
    {
        return $this->forwardFactory->create()->forward('noroute');
    }
}
