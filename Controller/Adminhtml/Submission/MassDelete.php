<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Controller\Adminhtml\SubmissionGridScope;
use Furan\FormBuilder\Model\Clock;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Submission\GridSelection;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

class MassDelete extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;
    use SubmissionGridScope;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_delete';
    public const BUDGET_SECONDS = 20;

    public function __construct(
        Context $context,
        private readonly GridSelection $selection,
        private readonly BatchDeleter $batchDeleter,
        private readonly Clock $clock
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $count = 0;
        $deadline = $this->clock->now() + self::BUDGET_SECONDS;
        try {
            foreach ($this->selection->batches(
                BatchDeleter::BATCH_SIZE,
                $this->getRequest()->getParam(Filter::SELECTED_PARAM),
                $this->getRequest()->getParam(Filter::EXCLUDED_PARAM)
            ) as $batch) {
                if ($this->clock->now() >= $deadline) {
                    // Deleted rows leave the selection, so running the action again carries on where this stopped.
                    $this->messageManager->addNoticeMessage(
                        __('Not all selected submissions were deleted yet. Run the action again to continue.')
                    );
                    break;
                }
                $count += $this->batchDeleter->deleteBatch($batch);
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        if ($count) {
            $this->messageManager->addSuccessMessage(__('A total of %1 submission(s) have been deleted.', $count));
        }
        return $this->redirectResult()->setPath('*/*/', $this->gridParams());
    }
}
