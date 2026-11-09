<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Definition;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormManagementInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Form\Identifier;
use Furan\FormBuilder\Api\FormWriteContext;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Magento\Store\Model\StoreManagerInterface;

class Importer
{
    public function __construct(
        private readonly FormManagementInterface $formManagement,
        private readonly FormResource $resource,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @throws InvalidSchemaException
     * @param array<string, mixed> $definition
     */
    public function import(array $definition, bool $update): FormInterface
    {
        $idByCode = $this->storeIdsByCode();
        return $this->formManagement->save(
            $this->existing($definition['identifier'] ?? null, $update),
            [
                'identifier' => $definition['identifier'] ?? null,
                'title' => $definition['title'] ?? null,
                'is_active' => $definition['is_active'] ?? true,
                'store_ids' => $this->storeIds($definition['store_codes'] ?? [], $idByCode),
                'schema' => $definition['schema'] ?? null,
                'settings' => $definition['settings'] ?? [],
                'translations' => $this->translationsByStoreId($definition['translations'] ?? [], $idByCode),
            ],
            FormWriteContext::allowAll()
        );
    }

    /** @throws InvalidSchemaException */
    private function existing(mixed $identifier, bool $update): ?FormInterface
    {
        if (!is_string($identifier) || !Identifier::isValid($identifier)) {
            return null;
        }
        $existingId = $this->resource->getIdByIdentifier($identifier);
        if ($existingId === null) {
            return null;
        }
        if (!$update) {
            throw new InvalidSchemaException([sprintf('A form with identifier "%s" already exists (use --update).', $identifier)]);
        }
        return $this->formRepository->getById($existingId);
    }

    /**
     * @param array<string, int> $idByCode
     * @return mixed a list of store ids; anything but a list is passed on for the writer to reject
     * @throws InvalidSchemaException
     */
    private function storeIds(mixed $codes, array $idByCode): mixed
    {
        if (!is_array($codes)) {
            return $codes;
        }
        $ids = [];
        $errors = [];
        foreach ($codes as $code) {
            if (is_string($code) && isset($idByCode[$code])) {
                $ids[] = $idByCode[$code];
            } else {
                $errors[] = (string)__('Store view %1 does not exist.', is_scalar($code) ? (string)$code : (string)json_encode($code));
            }
        }
        if ($errors !== []) {
            throw new InvalidSchemaException($errors);
        }
        return $ids;
    }

    /**
     * Definitions key translations by store code; codes this install doesn't have are dropped.
     * @param array<string, int> $idByCode
     * @return array<int, mixed>
     */
    private function translationsByStoreId(mixed $raw, array $idByCode): array
    {
        $byId = [];
        foreach ($idByCode as $code => $id) {
            if (is_array($raw) && isset($raw[$code])) {
                $byId[$id] = $raw[$code];
            }
        }
        return $byId;
    }

    /**
     * `getStores()` leaves out store 0 (all store views), so `admin` is mapped explicitly.
     * @return array<string, int>
     */
    private function storeIdsByCode(): array
    {
        $ids = [Exporter::ADMIN_CODE => 0];
        foreach ($this->storeManager->getStores() as $store) {
            $ids[(string)$store->getCode()] = (int)$store->getId();
        }
        return $ids;
    }
}
