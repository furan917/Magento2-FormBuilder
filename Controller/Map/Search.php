<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Map;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\NoStoreHeaders;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Map\GeocoderBusyException;
use Furan\FormBuilder\Model\Map\NominatimClient;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Server-side proxy in front of Nominatim; the browser never calls it directly.
 */
class Search implements HttpGetActionInterface
{
    use NoStoreHeaders;

    private const IP_LIMIT = 20;
    private const IP_WINDOW_SECONDS = 600;
    private const MIN_QUERY_LENGTH = 3;
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly NominatimClient $client,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    public function execute(): Json
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->isAvailable($storeId)) {
            return $this->error(404, []);
        }

        $query = $this->cleanQuery($this->request->getParam('q'));
        $length = mb_strlen($query);
        if ($length < self::MIN_QUERY_LENGTH || $length > self::MAX_QUERY_LENGTH) {
            return $this->error(400, []);
        }

        if (!$this->rateLimiter->allow('map', self::IP_LIMIT, self::IP_WINDOW_SECONDS)) {
            return $this->error(429, ['busy' => false]);
        }

        try {
            $results = $this->client->search($query, $storeId);
        } catch (GeocoderBusyException) {
            return $this->error(429, ['busy' => true]);
        }

        if ($results === null) {
            return $this->error(502, []);
        }

        return $this->jsonFactory->create()
            ->setHeader('Cache-Control', 'private, max-age=300', true)
            ->setData($results);
    }

    private function isAvailable(int $storeId): bool
    {
        if (!$this->config->isMapSearchEnabled($storeId) || $this->config->getMapProvider($storeId) !== 'osm') {
            return false;
        }
        try {
            $form = $this->formRepository->getActiveById((int)$this->request->getParam('form_id'), $storeId);
        } catch (NoSuchEntityException) {
            return false;
        }

        foreach (SchemaFields::all($form->getSchema()) as $field) {
            if (($field['type'] ?? '') === 'map') {
                return true;
            }
        }

        return false;
    }

    private function cleanQuery(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }

        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/', '', $raw));
    }

    /** @param array<string, mixed> $data */
    private function error(int $code, array $data): Json
    {
        return $this->noStore($this->jsonFactory->create())
            ->setHttpResponseCode($code)
            ->setData($data);
    }
}
