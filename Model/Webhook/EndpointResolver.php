<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/** Finds the endpoints that must receive an event of one submission. Scope is judged on the submission's own store. */
class EndpointResolver
{
    /** @var EndpointRoute[]|null */
    private ?array $active = null;

    public function __construct(
        private readonly EndpointRepository $endpoints,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /** @return EndpointRoute[] */
    public function match(int $formId, ?int $storeId, string $event): array
    {
        $websiteId = $this->websiteOf($storeId);
        if ($websiteId === null || !in_array($event, EndpointInput::EVENTS, true)) {
            return [];
        }

        return array_values(array_filter(
            $this->active(),
            static fn (EndpointRoute $e): bool => in_array($event, $e->events(), true)
                && ($e->allForms() || in_array($formId, $e->formIds(), true))
                && in_array($e->scopeType() === 'website' ? $websiteId : (int)$storeId, $e->scopeIds(), true)
        ));
    }

    private function websiteOf(?int $storeId): ?int
    {
        if ($storeId === null || $storeId < 1) {
            return null;
        }
        try {
            return (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /** @return EndpointRoute[] */
    private function active(): array
    {
        return $this->active ??= $this->endpoints->activeRoutes();
    }
}
