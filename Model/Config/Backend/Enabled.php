<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/** Flushes cached pages (including Varnish) so switching forms off takes effect immediately. */
class Enabled extends Value
{
    private const CACHE_TYPES = ['full_page', 'block_html'];

    public function afterSave()
    {
        if ($this->isValueChanged()) {
            $this->flushPages();
        }
        return parent::afterSave();
    }

    public function afterDelete()
    {
        $this->flushPages();
        return parent::afterDelete();
    }

    private function flushPages(): void
    {
        foreach (self::CACHE_TYPES as $type) {
            $this->cacheTypeList->cleanType($type);
            $this->_eventManager->dispatch('adminhtml_cache_refresh_type', ['type' => $type]);
        }
    }
}
