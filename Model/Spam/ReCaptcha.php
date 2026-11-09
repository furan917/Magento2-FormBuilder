<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Spam;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\ReCaptchaUi\Model\ErrorMessageConfigInterface;
use Magento\ReCaptchaUi\Model\IsCaptchaEnabledInterface;
use Magento\ReCaptchaUi\Model\UiConfigResolverInterface;
use Magento\ReCaptchaUi\Model\ValidationConfigResolverInterface;
use Magento\ReCaptchaValidationApi\Api\ValidatorInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Soft bridge to Magento reCAPTCHA. Its types appear only as ::class strings and are resolved
 * through the injected ObjectManager so the module compiles and runs without them.
 */
class ReCaptcha
{
    public const KEY = 'furan_formbuilder';
    private const CONFIG_PATH = 'recaptcha_frontend/type_for/furan_formbuilder';
    private const SCORE_CODE = 'score-threshold-not-met';
    private const BASE_MODULES = [
        'Magento_ReCaptchaUi',
        'Magento_ReCaptchaFrontendUi',
        'Magento_ReCaptchaValidation',
        'Magento_ReCaptchaValidationApi',
    ];
    private const VERSION_MODULES = [
        'recaptcha' => 'Magento_ReCaptchaVersion2Checkbox',
        'invisible' => 'Magento_ReCaptchaVersion2Invisible',
        'recaptcha_v3' => 'Magento_ReCaptchaVersion3Invisible',
    ];

    /** @var array<int, bool> by website id */
    private array $enabled = [];
    /** @var array<int, bool> by website id */
    private array $configured = [];

    /**
     * @param string[] $requiredInterfaces
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly array $requiredInterfaces = [
            IsCaptchaEnabledInterface::class,
            UiConfigResolverInterface::class,
            ValidationConfigResolverInterface::class,
            ValidatorInterface::class,
            ErrorMessageConfigInterface::class,
        ]
    ) {
    }

    /** For the current website: the configuration and the reCAPTCHA module's own check. */
    public function isEnabled(): bool
    {
        $websiteId = (int)$this->storeManager->getWebsite()->getId();
        if (isset($this->enabled[$websiteId])) {
            return $this->enabled[$websiteId];
        }
        $this->enabled[$websiteId] = false;
        if (!$this->isEnabledForWebsite($websiteId)) {
            return false;
        }
        try {
            $this->enabled[$websiteId] = (bool)$this->service(IsCaptchaEnabledInterface::class)
                ->isCaptchaEnabledFor(self::KEY);
        } catch (\Throwable $e) {
            $this->logger->warning('Form Builder reCAPTCHA: ' . $e->getMessage());
        }
        return $this->enabled[$websiteId];
    }

    /**
     * From configuration only, so it works from the admin: the reCAPTCHA module's check reads the current
     * website, and in the admin area its backend settings.
     */
    public function isEnabledForWebsite(int $websiteId): bool
    {
        return $this->configured[$websiteId] ??= $this->isConfigured($websiteId);
    }

    private function isConfigured(int $websiteId): bool
    {
        $type = $this->scopeConfig->getValue(self::CONFIG_PATH, 'website', $websiteId);
        if (!is_string($type) || $this->versionModule($type) === null || $this->disabledModule($type) !== null) {
            return false;
        }
        foreach ($this->requiredInterfaces as $interface) {
            if (!interface_exists($interface)) {
                return false;
            }
        }
        foreach (['public_key', 'private_key'] as $key) {
            $value = $this->scopeConfig->getValue('recaptcha_frontend/type_' . $type . '/' . $key, 'website', $websiteId);
            if (trim((string)$value) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{rendering: array<mixed>, invisible: bool}|null
     */
    public function getUiConfig(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $ui = $this->service(UiConfigResolverInterface::class)->get(self::KEY);
        return ['rendering' => $ui['rendering'] ?? [], 'invisible' => (bool)($ui['invisible'] ?? false)];
    }

    /**
     * The reCAPTCHA module still resolves the remote IP through core, not through the submission input.
     *
     * @return string|null null = pass, otherwise the user-facing error
     */
    public function verifyToken(string $token): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $messages = $this->service(ErrorMessageConfigInterface::class);
        if ($token === '') {
            return $messages->getValidationFailureMessage();
        }
        try {
            $config = $this->service(ValidationConfigResolverInterface::class)->get(self::KEY);
            $result = $this->service(ValidatorInterface::class)->isValid($token, $config);
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder reCAPTCHA validation failed: ' . $e->getMessage());
            return $messages->getTechnicalFailureMessage();
        }
        if ($result->isValid()) {
            return null;
        }
        $codes = array_keys($result->getErrors());
        if ($codes === [self::SCORE_CODE]) {
            return $messages->getValidationFailureMessage();
        }
        foreach ($codes as $code) {
            $this->logger->error(sprintf("reCAPTCHA '%s' form error: %s", self::KEY, $code));
        }
        return $messages->getTechnicalFailureMessage();
    }

    /** The only ObjectManager use; callers check isEnabled() first. */
    private function service(string $class): mixed
    {
        return $this->objectManager->get($class);
    }

    /**
     * Name of the first disabled module the configured type needs, or null. Needs no ObjectManager.
     */
    public function getUnavailableReason(int $websiteId): ?string
    {
        $type = $this->scopeConfig->getValue(self::CONFIG_PATH, 'website', $websiteId);
        return $this->versionModule($type) === null ? null : $this->disabledModule($type);
    }

    private function versionModule(mixed $type): ?string
    {
        return is_string($type) ? (self::VERSION_MODULES[$type] ?? null) : null;
    }

    private function disabledModule(mixed $type): ?string
    {
        foreach ([...self::BASE_MODULES, $this->versionModule($type)] as $module) {
            if ($module !== null && !$this->moduleManager->isEnabled($module)) {
                return $module;
            }
        }
        return null;
    }
}
