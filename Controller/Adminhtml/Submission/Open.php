<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;

/** Target of submission links in emails, which cannot carry a secret key; it only redirects to the keyed view. */
class Open extends Action implements HttpGetActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions';

    /** @var string[] */
    protected $_publicActions = ['open'];

    public function execute()
    {
        $id = $this->getRequest()->getParam('id');
        $redirect = $this->redirectResult();
        if (!is_string($id) || !ctype_digit($id) || (int)$id <= 0) {
            return $redirect->setPath('furanform/submission/index');
        }
        return $redirect->setPath('furanform/submission/view', ['id' => (int)$id]);
    }
}
