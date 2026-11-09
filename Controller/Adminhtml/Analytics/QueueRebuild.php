<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Analytics;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Stats\RebuildQueue;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class QueueRebuild extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::analytics';

    public function __construct(Context $context, private readonly RebuildQueue $queue)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $raw = $this->getRequest()->getParam('form_id');
        try {
            $this->queue->request($raw === null || $raw === '' ? null : (int)$raw);
            $this->messageManager->addSuccessMessage(
                __('Statistics rebuild queued. It runs during the next off-peak window.')
            );
        } catch (\Exception) {
            // e.g. FK failure for an unknown form id
            $this->messageManager->addErrorMessage(__('The requested form was not found.'));
        }
        return $this->redirectResult()->setRefererOrBaseUrl();
    }
}
