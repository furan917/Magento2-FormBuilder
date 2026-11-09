<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Form\Identifier;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url;
use Magento\Store\Model\StoreManagerInterface;

class Router implements RouterInterface
{
    private const MODULE = 'furanform';

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly PageResource $pageResource
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof Http) {
            return null;
        }
        if ($request->getModuleName() === self::MODULE) {
            return null;
        }

        $path = trim((string)$request->getPathInfo(), '/');
        $pattern = '#^' . preg_quote($this->config->getRoutePrefix(), '#') . '/(' . Identifier::PATTERN_BODY . ')$#D';
        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId)) {
            return null;
        }
        try {
            $form = $this->formRepository->getActiveByIdentifier($matches[1], $storeId);
        } catch (NoSuchEntityException) {
            if ($this->pageResource->checkIdentifier($path, $storeId)) {
                return null;
            }
            $request->setModuleName(self::MODULE)->setControllerName('form')->setActionName('notFound');
            return $this->actionFactory->create(Forward::class);
        }

        $request->setModuleName(self::MODULE)->setControllerName('form')->setActionName('view')
            ->setParam('form_id', $form->getId());
        $request->setAlias(Url::REWRITE_REQUEST_PATH_ALIAS, $path);

        return $this->actionFactory->create(Forward::class);
    }
}
