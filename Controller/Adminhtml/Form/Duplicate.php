<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\FormManagementInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Form\Identifier;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

class Duplicate extends AbstractForm implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::forms_save';

    public function __construct(
        Context $context,
        private readonly FormRepositoryInterface $formRepository,
        private readonly FormManagementInterface $formManagement,
        private readonly FormResource $formResource,
        private readonly SchemaMigrator $schemaMigrator
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $source = $this->formRepository->getById((int)$this->getRequest()->getParam('id'));
            // The writer would refuse too, but only with "cannot be changed here"; this message fits a duplicate.
            if (!$this->schemaMigrator->isSupported($source->getSchema())) {
                throw new LocalizedException(__(
                    'This form was saved by a newer version of the module (schema version %1) and cannot be duplicated.',
                    $this->schemaMigrator->versionOf($source->getSchema())
                ));
            }
            $suffix = ' ' . __('(copy)');
            $copy = $this->formManagement->save(
                null,
                [
                    'identifier' => $this->uniqueIdentifier($source->getIdentifier()),
                    'title' => mb_substr($source->getTitle(), 0, Identifier::MAX_TITLE_LENGTH - mb_strlen($suffix)) . $suffix,
                    'is_active' => false,
                    'store_ids' => $source->getStoreIds(),
                    'schema' => $source->getSchema(),
                    'settings' => $source->getSettings(),
                    'translations' => $source->getTranslations(),
                ],
                $this->writeContext(),
                $source
            );
            $this->messageManager->addSuccessMessage(
                __('The form has been duplicated. You are editing the copy, which is disabled until you enable it.')
            );
            return $this->redirectResult()
                ->setPath('*/*/edit', ['id' => $copy->getId()]);
        } catch (InvalidSchemaException $e) {
            foreach ($e->getErrors() as $error) {
                $this->messageManager->addErrorMessage($error);
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->redirectResult()->setPath('*/*/');
    }

    private function uniqueIdentifier(string $identifier): string
    {
        $suffix = '-copy';
        $counter = 1;
        do {
            $candidate = $this->fit($identifier, $suffix);
            $counter++;
            $suffix = '-copy-' . $counter;
        } while ($this->formResource->getIdByIdentifier($candidate) !== null);
        return $candidate;
    }

    private function fit(string $identifier, string $suffix): string
    {
        return substr($identifier, 0, Identifier::MAX_LENGTH - strlen($suffix)) . $suffix;
    }
}
