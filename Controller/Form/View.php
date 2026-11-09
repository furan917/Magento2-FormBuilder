<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Block\Form;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Seo\FormUrls;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class View implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly Http $request,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ForwardFactory $forwardFactory,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
        private readonly FormUrls $formUrls
    ) {
    }

    public function execute(): Page|Forward
    {
        if (!$this->request->getAlias(Url::REWRITE_REQUEST_PATH_ALIAS)) {
            return $this->noroute();
        }

        $store = $this->storeManager->getStore();
        try {
            $form = $this->formRepository->getActiveById((int)$this->request->getParam('form_id'), (int)$store->getId());
        } catch (NoSuchEntityException) {
            return $this->noroute();
        }
        $form = $this->translator->localize($form, (int)$store->getId());

        $page = $this->pageFactory->create();
        /** @var Form|false $block */
        $block = $page->getLayout()->getBlock('furanform.form');
        if (!$block) {
            $this->logger->error('Furan_FormBuilder: block furanform.form is missing from the layout.');
            return $this->noroute();
        }
        $block->setForm($form);

        $settings = $form->getSettings();
        $config = $page->getConfig();
        $title = trim((string)($settings['page_title'] ?? ''));
        $config->getTitle()->set($title !== '' ? $title : $form->getTitle());
        $block->setData('page_heading', $title !== '' ? $title : $form->getTitle());
        $description = trim((string)($settings['meta_description'] ?? ''));
        if ($description !== '') {
            $config->setDescription($description);
        }
        $config->addBodyClass('furanform-view');
        if (!empty($settings['seo_noindex'])) {
            $config->setRobots('NOINDEX,FOLLOW');
        }
        // Tracking parameters (utm_*, used by hidden fields) must not create duplicate pages.
        $config->addRemotePageAsset($this->formUrls->url($form, (int)$store->getId()), 'canonical', ['attributes' => ['rel' => 'canonical']]);
        foreach ($this->formUrls->alternates($form) as $hreflang => $url) {
            $config->addRemotePageAsset(
                $url,
                'alternate',
                ['attributes' => ['rel' => 'alternate', 'hreflang' => $hreflang]],
                'furanform-alternate-' . $hreflang
            );
        }

        return $page;
    }

    private function noroute(): Forward
    {
        return $this->forwardFactory->create()->forward('noroute');
    }
}
