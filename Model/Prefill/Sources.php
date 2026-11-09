<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill;

use Furan\FormBuilder\Api\PrefillSourceInterface;
use Psr\Log\LoggerInterface;

class Sources
{
    public const GROUP_CUSTOMER = 'customer';
    public const GROUP_PRODUCT = 'product';

    /** @var array<string, PrefillSourceInterface> */
    private readonly array $sources;

    /**
     * @param PrefillSourceInterface[] $sources keyed by source code
     */
    public function __construct(array $sources = [], ?LoggerInterface $logger = null)
    {
        $valid = [];
        foreach ($sources as $key => $source) {
            if (!$source instanceof PrefillSourceInterface || $source->getCode() !== (string)$key) {
                $logger?->warning(sprintf('Prefill source pool item "%s" skipped: it does not match its code.', $key));
                continue;
            }
            $valid[$key] = $source;
        }
        $this->sources = $valid;
    }

    /**
     * @return array<string, PrefillSourceInterface>
     */
    public function all(): array
    {
        return $this->sources;
    }

    public function get(string $code): ?PrefillSourceInterface
    {
        return $this->sources[$code] ?? null;
    }

    public function isValid(string $code): bool
    {
        return isset($this->sources[$code]);
    }

    public function isProduct(string $code): bool
    {
        return ($this->sources[$code] ?? null)?->getGroup() === self::GROUP_PRODUCT;
    }

    public function suits(string $code, string $fieldType): bool
    {
        return ($this->sources[$code] ?? null)?->suitsType($fieldType) === true;
    }
}
