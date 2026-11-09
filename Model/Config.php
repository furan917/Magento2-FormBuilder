<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

use Furan\FormBuilder\Model\Email\SafeAddress;
use Furan\FormBuilder\Model\Config\Backend\TileUrl;
use Furan\FormBuilder\Model\Map\AttributionSanitizer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\File\Size;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const PATH = 'furan_formbuilder/';
    private const DEFAULT_ROUTE_PREFIX = 'forms';
    public const ROUTE_PREFIX_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';
    private const DEFAULT_SENDER_IDENTITY = 'general';
    private const DELETE_BEHAVIOURS = ['anonymise', 'keep', 'delete'];
    private const DEFAULT_DELETE_BEHAVIOUR = 'anonymise';
    private const DEFAULT_SERVER_DRAFT_DAYS = 30;
    private const DEFAULT_MAX_THREAD_MESSAGES = 200;
    private const MAP_PROVIDERS = ['osm', 'google'];
    private const DEFAULT_MAP_PROVIDER = 'osm';
    private const DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    public const DEFAULT_SEARCH_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const DEFAULT_MAX_ZOOM = 19;
    public const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'inc', 'pgif', 'cgi',
        'pl', 'py', 'sh', 'exe', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl',
        'swf', 'htaccess', 'asp', 'aspx', 'jsp',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Size $fileSize,
        private readonly AttributionSanitizer $attributionSanitizer
    ) {
    }

    public function getRoutePrefix(?int $storeId = null): string
    {
        $prefix = $this->value('general/route_prefix', $storeId);

        return is_string($prefix) && preg_match(self::ROUTE_PREFIX_PATTERN, $prefix) === 1
            ? $prefix
            : self::DEFAULT_ROUTE_PREFIX;
    }

    public function getMinSubmitSeconds(?int $storeId = null): int
    {
        return $this->intValue('spam/min_seconds', $storeId);
    }

    public function getRateLimit(?int $storeId = null): int
    {
        return $this->intValue('spam/rate_limit', $storeId);
    }

    public function getFormRateLimit(?int $storeId = null): int
    {
        return $this->intValue('spam/form_rate_limit', $storeId);
    }

    public function getRateWindowMinutes(?int $storeId = null): int
    {
        return $this->intValue('spam/rate_window_minutes', $storeId);
    }

    /** @return string[] addresses or CIDR ranges allowed to set X-Forwarded-For (global scope) */
    public function getTrustedProxies(): array
    {
        $ranges = [];
        $configured = preg_split('/[\s,]+/', (string)$this->value('spam/trusted_proxies', null), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($configured === false ? [] : $configured as $range) {
            [$ip, $bits] = array_pad(explode('/', $range, 2), 2, null);
            $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false
                && ($bits === null || (ctype_digit($bits) && (int)$bits <= $max))
            ) {
                $ranges[] = $range;
            }
        }

        return array_values(array_unique($ranges));
    }

    /** Bytes of uploads one form may store per hour; 0 = unlimited. */
    public function getUploadBudgetBytes(?int $storeId = null): int
    {
        return $this->intValue('uploads/form_budget_mb', $storeId) * 1048576;
    }

    public function getMaxUploadMb(?int $storeId = null): int
    {
        return min($this->intValue('uploads/max_size_mb', $storeId), (int)$this->fileSize->getMaxFileSizeInMb());
    }

    /** @return string[] */
    public function getAllowedExtensions(?int $storeId = null): array
    {
        $raw = (string)$this->value('uploads/allowed_extensions', $storeId);
        $extensions = [];
        foreach (explode(',', $raw) as $part) {
            $extension = strtolower(ltrim(trim($part), '.'));
            if ($extension !== '' && !in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
                $extensions[$extension] = $extension;
            }
        }

        return array_values($extensions);
    }

    public function getRetentionDays(?int $storeId = null): int
    {
        return $this->intValue('privacy/retention_days', $storeId);
    }

    public function getDraftTtlHours(?int $storeId = null): int
    {
        return $this->intValue('privacy/draft_ttl_hours', $storeId);
    }

    public function getSenderIdentity(?int $storeId = null): string
    {
        $identity = trim((string)$this->value('email/sender_identity', $storeId));

        return $identity !== '' ? $identity : self::DEFAULT_SENDER_IDENTITY;
    }

    /** Reply-To for admin replies; '' = none. Header-injection safe. */
    public function getReplyTo(?int $storeId = null): string
    {
        $address = trim((string)$this->value('email/reply_to', $storeId));

        return SafeAddress::isValid($address) ? $address : '';
    }

    public function getAccountDeletionBehaviour(): string
    {
        $value = $this->value('privacy/on_customer_delete', null);

        return is_string($value) && in_array($value, self::DELETE_BEHAVIOURS, true)
            ? $value
            : self::DEFAULT_DELETE_BEHAVIOUR;
    }

    /** Store-level kill switch: off hides every form, widget, endpoint and account page on the storefront. */
    public function isEnabled(?int $storeId = null): bool
    {
        return (bool)$this->value('general/enabled', $storeId);
    }

    /** Global, like email/async: whether a queue consumer runs is a property of the installation. */
    public function isAsyncActions(): bool
    {
        return (bool)$this->value('general/async_actions', null);
    }

    /** Template id for an email setting (`notification`, `autoresponder`, `reply`, `customer_reply`, `claim`). */
    public function getEmailTemplate(string $setting, ?int $storeId = null): string
    {
        $chosen = trim((string)$this->value('email/' . $setting . '_template', $storeId));

        return $chosen !== '' ? $chosen : 'furan_formbuilder_email_' . $setting . '_template';
    }

    /** Global: whether a queue consumer runs is a property of the installation, not of a store. */
    public function isAsyncMail(): bool
    {
        return (bool)$this->value('email/async', null);
    }

    /** Confirmation emails per store view per day; 0 = unlimited. */
    public function getAutoresponderDailyLimit(?int $storeId = null): int
    {
        $value = $this->value('email/autoresponder_daily_limit', $storeId);
        return $value === null || $value === '' ? 500 : max(0, (int)$value);
    }

    public function isClaimEnabled(?int $storeId = null): bool
    {
        return (bool)$this->value('customers/claim_enabled', $storeId);
    }

    public function isDraftSyncEnabled(?int $storeId = null): bool
    {
        return (bool)$this->value('customers/draft_sync', $storeId);
    }

    public function getServerDraftDays(?int $storeId = null): int
    {
        return $this->intValue('customers/draft_days', $storeId) ?: self::DEFAULT_SERVER_DRAFT_DAYS;
    }

    public function isCustomerRepliesEnabled(?int $storeId = null): bool
    {
        return (bool)$this->value('customers/replies_enabled', $storeId);
    }

    public function isGuestReplyLinksEnabled(?int $storeId = null): bool
    {
        return (bool)$this->value('customers/guest_reply_links', $storeId);
    }

    /** Default scope; 0 = unlimited. */
    public function getMaxThreadMessages(): int
    {
        $value = $this->value('customers/max_thread_messages', null);

        return $value === null || $value === '' ? self::DEFAULT_MAX_THREAD_MESSAGES : max(0, (int)$value);
    }

    public function isAnalyticsEnabled(): bool
    {
        return (bool)$this->value('analytics/enabled', null);
    }

    public function getMapProvider(?int $storeId = null): string
    {
        $provider = $this->value('maps/provider', $storeId);

        return is_string($provider) && in_array($provider, self::MAP_PROVIDERS, true)
            ? $provider
            : self::DEFAULT_MAP_PROVIDER;
    }

    /** ScopeConfig already returns the decrypted value; '' means no key. */
    public function getGoogleApiKey(?int $storeId = null): string
    {
        return $this->getMapProvider($storeId) === 'google'
            ? trim((string)$this->value('maps/google_api_key', $storeId))
            : '';
    }

    public function getGoogleMapId(?int $storeId = null): string
    {
        return trim((string)$this->value('maps/google_map_id', $storeId));
    }

    public function getTileUrl(?int $storeId = null): string
    {
        $url = trim((string)$this->value('maps/tile_url', $storeId));

        return TileUrl::validate($url) === null ? $url : self::DEFAULT_TILE_URL;
    }

    public function getAttribution(?int $storeId = null): string
    {
        return $this->attributionSanitizer->sanitize((string)$this->value('maps/attribution', $storeId));
    }

    public function getMaxZoom(?int $storeId = null): int
    {
        $zoom = $this->value('maps/max_zoom', $storeId);

        return $zoom === null || $zoom === '' ? self::DEFAULT_MAX_ZOOM : max(1, min(22, (int)$zoom));
    }

    public function isMapSearchEnabled(?int $storeId = null): bool
    {
        return (bool)$this->value('maps/search_enabled', $storeId);
    }

    public function getMapSearchEndpoint(?int $storeId = null): string
    {
        $url = trim((string)$this->value('maps/search_endpoint', $storeId));

        return $url === '' ? self::DEFAULT_SEARCH_ENDPOINT : $url;
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue(self::PATH . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function intValue(string $path, ?int $storeId): int
    {
        return max(0, (int)$this->value($path, $storeId));
    }
}
