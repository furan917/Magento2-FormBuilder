<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Controller\Adminhtml\SubmissionGridScope;
use Furan\FormBuilder\Model\Clock;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\GridSelection;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

class MassStatus extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;
    use SubmissionGridScope;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_reply';
    public const BUDGET_SECONDS = 20;
    private const BATCH_SIZE = 500;

    public function __construct(
        Context $context,
        private readonly GridSelection $selection,
        private readonly SubmissionStatus $statuses,
        private readonly StatusWriter $writer,
        private readonly Clock $clock
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $status = $this->statuses->parse($this->getRequest()->getParam('status'));
        $redirect = $this->redirectResult();
        if ($status === null) {
            $this->messageManager->addErrorMessage(__('Please select a valid status.'));
            return $redirect->setPath('*/*/', $this->gridParams());
        }
        $count = 0;
        try {
            $deadline = $this->clock->now() + self::BUDGET_SECONDS;
            // Rows already in the target status are skipped, so running the action again carries on.
            foreach ($this->selection->batches(
                self::BATCH_SIZE,
                $this->getRequest()->getParam(Filter::SELECTED_PARAM),
                $this->getRequest()->getParam(Filter::EXCLUDED_PARAM),
                ['main_table.status <> ?' => $status]
            ) as $batch) {
                if ($this->clock->now() >= $deadline) {
                    $this->messageManager->addNoticeMessage(
                        __('Not all selected submissions were updated yet. Run the action again to continue.')
                    );
                    break;
                }
                $count += $this->writer->change(array_map('intval', $batch), $status, 'admin');
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        if ($count) {
            $this->messageManager->addSuccessMessage(__('A total of %1 submission(s) have been updated.', $count));
        }
        return $redirect->setPath('*/*/', $this->gridParams());
    }
}
