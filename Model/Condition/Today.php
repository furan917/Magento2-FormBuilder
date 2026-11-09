<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Today
{
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * The calendar day (Y-m-d) in the store view's timezone.
     *
     * @param string|null $utcInstant a UTC `Y-m-d H:i:s` such as a submission's created_at; null or unusable = now
     */
    public function forStore(int $storeId, ?string $utcInstant = null): string
    {
        $utc = new \DateTimeZone('UTC');
        $instant = $this->parse($utcInstant, $utc) ?? new \DateTimeImmutable('now', $utc);
        return $instant->setTimezone($this->zone($storeId, $utc))->format('Y-m-d');
    }

    private function parse(?string $instant, \DateTimeZone $utc): ?\DateTimeImmutable
    {
        if ($instant === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::DB_FORMAT, $instant, $utc);
        return $parsed !== false && $parsed->format(self::DB_FORMAT) === $instant ? $parsed : null;
    }

    private function zone(int $storeId, \DateTimeZone $fallback): \DateTimeZone
    {
        $name = $this->scopeConfig->getValue('general/locale/timezone', ScopeInterface::SCOPE_STORE, $storeId);
        if (!is_string($name) || $name === '') {
            return $fallback;
        }
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
