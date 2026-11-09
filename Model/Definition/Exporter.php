<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Definition;

use Furan\FormBuilder\Api\Data\FormInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class Exporter
{
    public const ADMIN_CODE = 'admin';

    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(FormInterface $form): array
    {
        // Keyed by store code: ids differ between installs.
        $translations = [];
        foreach ($form->getTranslations() as $storeId => $overlay) {
            $code = $this->code($storeId);
            if ($code !== null) {
                $translations[$code] = $overlay;
            }
        }
        $storeCodes = array_values(array_filter(
            array_map($this->code(...), array_map('intval', $form->getStoreIds())),
            static fn (?string $code): bool => $code !== null
        ));

        return [
            'identifier' => $form->getIdentifier(),
            'title' => $form->getTitle(),
            'is_active' => $form->isActive(),
            'store_codes' => $storeCodes,
            'schema' => $form->getSchema(),
            'settings' => $form->getSettings(),
            'translations' => $translations,
        ];
    }

    /** Store 0 (all store views) is not in the store manager's list; its Magento code is `admin`. */
    private function code(int $storeId): ?string
    {
        if ($storeId === 0) {
            return self::ADMIN_CODE;
        }
        try {
            return $this->storeManager->getStore($storeId)->getCode();
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
