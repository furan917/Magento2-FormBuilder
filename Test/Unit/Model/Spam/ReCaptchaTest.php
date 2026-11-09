<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Spam;

use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Validation\ValidationResult;
use Magento\ReCaptchaUi\Model\ErrorMessageConfigInterface;
use Magento\ReCaptchaUi\Model\IsCaptchaEnabledInterface;
use Magento\ReCaptchaUi\Model\UiConfigResolverInterface;
use Magento\ReCaptchaUi\Model\ValidationConfigResolverInterface;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magento\ReCaptchaValidationApi\Api\ValidatorInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReCaptchaTest extends TestCase
{
    private const PATH = 'recaptcha_frontend/type_for/furan_formbuilder';

    /** @var ModuleManager&MockObject */
    private $modules;
    /** @var ObjectManagerInterface&MockObject */
    private $om;
    /** @var LoggerInterface&MockObject */
    private $logger;
    /** @var ScopeConfigInterface&MockObject */
    private $config;
    /** @var array<string,object> */
    private array $services = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?ScopeConfigInterface $config = null): void
    {
        $this->modules = $this->createStub(ModuleManager::class);
        $this->modules->method('isEnabled')->willReturn(true);
        $this->om = $this->configureOm($this->createStub(ObjectManagerInterface::class));
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->config = $config ?? $this->createStub(ScopeConfigInterface::class);
    }

    /** @var array<string, string|null> */
    private array $keys = ['public_key' => 'site-key', 'private_key' => 'secret'];
    private int $currentWebsite = 1;

    /** $type and the keys are set on $websiteId only. */
    private function configure(mixed $type, int $websiteId = 1): void
    {
        $this->config->method('getValue')->willReturnCallback(
            fn (string $path, string $scope, mixed $id = null): mixed => $scope !== 'website' || (int)$id !== $websiteId
                ? null
                : ($path === self::PATH ? $type : ($this->keys[basename($path)] ?? null))
        );
    }

    private function storeManager(): StoreManagerInterface
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturnCallback(fn (): int => $this->currentWebsite);
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getWebsite')->willReturn($website);
        return $manager;
    }

    private function bridge(?array $interfaces = null): ReCaptcha
    {
        return $interfaces === null
            ? new ReCaptcha($this->modules, $this->om, $this->logger, $this->config, $this->storeManager())
            : new ReCaptcha($this->modules, $this->om, $this->logger, $this->config, $this->storeManager(), $interfaces);
    }

    private function enable(bool $enabled = true): void
    {
        $gate = $this->createMock(IsCaptchaEnabledInterface::class);
        $gate->method('isCaptchaEnabledFor')->with('furan_formbuilder')->willReturn($enabled);
        $this->services[IsCaptchaEnabledInterface::class] = $gate;
    }

    private function messages(): void
    {
        $m = $this->createStub(ErrorMessageConfigInterface::class);
        $m->method('getValidationFailureMessage')->willReturn('VALIDATION');
        $m->method('getTechnicalFailureMessage')->willReturn('TECHNICAL');
        $this->services[ErrorMessageConfigInterface::class] = $m;
    }

    private function validator(ValidationResult|\Throwable $outcome): void
    {
        $this->services[ValidationConfigResolverInterface::class] = $this->createConfiguredStub(
            ValidationConfigResolverInterface::class,
            ['get' => $this->createStub(ValidationConfigInterface::class)]
        );
        $v = $this->createStub(ValidatorInterface::class);
        if ($outcome instanceof \Throwable) {
            $v->method('isValid')->willThrowException($outcome);
        } else {
            $v->method('isValid')->willReturn($outcome);
        }
        $this->services[ValidatorInterface::class] = $v;
    }

    /** @return array<string,array{0:mixed}> */
    public static function emptyTypes(): array
    {
        return ['empty string' => [''], 'null' => [null]];
    }

    /**
     * @template T of ObjectManagerInterface&Stub
     * @param T $om
     * @return T
     */
    private function configureOm(ObjectManagerInterface&Stub $om): ObjectManagerInterface&Stub
    {
        $om->method('get')->willReturnCallback(fn(string $c) => $this->services[$c]);
        return $om;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyTypes')]
    public function testItReportsDisabledWithoutTouchingTheObjectManagerWhenTheFormBuilderTypeIsEmptyOrNull(
        mixed $type
    ): void {
        $this->fixture();
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $om = $this->om = $this->configureOm($this->createMock(ObjectManagerInterface::class));
        $this->configure($type);
        $om->expects($this->never())->method('get');
        $logger->expects($this->never())->method($this->anything());
        $this->assertFalse($this->bridge()->isEnabled());
        $this->assertNull($this->bridge()->getUiConfig());
    }

    public function testItReportsDisabledForATypeOtherThanRecaptchaInvisibleAndRecaptchaV3(): void
    {
        $this->fixture();
        $om = $this->om = $this->configureOm($this->createMock(ObjectManagerInterface::class));
        $this->configure('thirdparty');
        $om->expects($this->never())->method('get');
        $this->assertFalse($this->bridge()->isEnabled());
    }

    public function testItReportsDisabledWhenTheReCaptchaModulesAreNotEnabled(): void
    {
        $this->fixture();
        $om = $this->om = $this->configureOm($this->createMock(ObjectManagerInterface::class));
        $this->modules = $this->createStub(ModuleManager::class);
        $this->modules->method('isEnabled')->willReturn(false);
        $this->configure('recaptcha_v3');
        $om->expects($this->never())->method('get');
        $this->assertFalse($this->bridge()->isEnabled());
    }

    public function testItReportsDisabledWhenTheReCaptchaInterfacesDoNotExist(): void
    {
        $this->fixture();
        $om = $this->om = $this->configureOm($this->createMock(ObjectManagerInterface::class));
        $this->configure('recaptcha_v3');
        $om->expects($this->never())->method('get');
        $this->assertFalse($this->bridge(['Nope\\ReCaptcha\\Missing'])->isEnabled());
    }

    public function testItReportsDisabledAndLogsOnceWhenTheConfiguredTypeThrowsAnInputException(): void
    {
        $this->fixture();
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->configure('invisible');
        $gate = $this->createStub(IsCaptchaEnabledInterface::class);
        $gate->method('isCaptchaEnabledFor')->willThrowException(new InputException(__('bad')));
        $this->services[IsCaptchaEnabledInterface::class] = $gate;
        $logger->expects($this->once())->method('warning');
        $bridge = $this->bridge();
        $this->assertFalse($bridge->isEnabled());
        $this->assertFalse($bridge->isEnabled());
    }

    public function testItReadsAWebsitesTypeAndKeysWithoutTheObjectManager(): void
    {
        $this->fixture();
        $om = $this->om = $this->configureOm($this->createMock(ObjectManagerInterface::class));
        $this->configure('recaptcha_v3', 2);
        $om->expects($this->never())->method('get');
        $bridge = $this->bridge();
        $this->assertTrue($bridge->isEnabledForWebsite(2));
        $this->assertFalse($bridge->isEnabledForWebsite(1));
    }

    public function testItReportsAWebsiteWithoutBothKeysAsDisabled(): void
    {
        foreach (['public_key', 'private_key'] as $missing) {
            $this->fixture();
            $this->keys = ['public_key' => 'site-key', 'private_key' => 'secret', $missing => ' '];
            $this->configure('recaptcha', 2);
            $this->assertFalse($this->bridge()->isEnabledForWebsite(2), $missing);
        }
    }

    public function testItChecksTheCurrentWebsiteOnTheStorefrontAndCachesTheAnswerPerWebsite(): void
    {
        $this->fixture();
        $this->configure('invisible', 2);
        $gate = $this->createMock(IsCaptchaEnabledInterface::class);
        $gate->expects($this->once())->method('isCaptchaEnabledFor')->with('furan_formbuilder')->willReturn(true);
        $this->services[IsCaptchaEnabledInterface::class] = $gate;
        $bridge = $this->bridge();

        $this->assertFalse($bridge->isEnabled());
        $this->currentWebsite = 2;
        $this->assertTrue($bridge->isEnabled());
        $this->assertTrue($bridge->isEnabled());
    }

    public function testItReturnsTheWidgetConfigForTheFormTypeWhenEnabled(): void
    {
        $this->fixture();
        $this->configure('recaptcha');
        $this->enable();
        $ui = $this->createMock(UiConfigResolverInterface::class);
        $ui->method('get')->with('furan_formbuilder')
            ->willReturn(['rendering' => ['sitekey' => 'k'], 'invisible' => 1, 'secret' => 'x']);
        $this->services[UiConfigResolverInterface::class] = $ui;
        $this->assertSame(
            ['rendering' => ['sitekey' => 'k'], 'invisible' => true],
            $this->bridge()->getUiConfig()
        );
    }

    public function testItAcceptsATokenTheValidatorMarksValid(): void
    {
        $this->fixture();
        $this->configure('recaptcha');
        $this->enable();
        $this->messages();
        $this->validator(new ValidationResult([]));
        $this->assertNull($this->bridge()->verifyToken('tok'));
    }

    public function testItReturnsTheValidationFailureMessageOnlyForAScoreThresholdMissAndTheTechnicalMessageOtherwise(): void
    {
        $this->fixture();
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->configure('recaptcha_v3');
        $this->enable();
        $this->messages();
        $this->validator(new ValidationResult(['score-threshold-not-met' => 'low']));
        $this->assertSame('VALIDATION', $this->bridge()->verifyToken('tok'));

        $this->services = [];
        $this->enable();
        $this->messages();
        $this->validator(new ValidationResult(['invalid-input-secret' => 'bad', 'score-threshold-not-met' => 'low']));
        $logger->expects($this->exactly(2))->method('error');
        $this->assertSame('TECHNICAL', $this->bridge()->verifyToken('tok'));
    }

    public function testItReturnsTheValidationFailureMessageWithoutLoggingAnErrorWhenTheTokenIsMissing(): void
    {
        $this->fixture();
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->configure('recaptcha');
        $this->enable();
        $this->messages();
        $logger->expects($this->never())->method('error');
        $this->assertSame('VALIDATION', $this->bridge()->verifyToken(''));
    }

    public function testItReturnsTheTechnicalFailureMessageAndLogsAnErrorWhenTheValidatorThrows(): void
    {
        $this->fixture();
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->configure('recaptcha');
        $this->enable();
        $this->messages();
        $this->validator(new \RuntimeException('google down'));
        $logger->expects($this->once())->method('error');
        $this->assertSame('TECHNICAL', $this->bridge()->verifyToken('tok'));
    }

    public function testItReportsAConfiguredTypeWhoseVersionModuleIsDisabledAsUnavailableForThatWebsite(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $this->fixture(config: $config);
        $om = $this->om = $this->configureOm($this->createMock(ObjectManagerInterface::class));
        $this->modules = $this->createStub(ModuleManager::class);
        $this->modules->method('isEnabled')
            ->willReturnCallback(fn(string $m) => $m !== 'Magento_ReCaptchaVersion3Invisible');
        $config->method('getValue')->with(self::PATH, 'website', 4)->willReturn('recaptcha_v3');
        $om->expects($this->never())->method('get');
        $this->assertSame('Magento_ReCaptchaVersion3Invisible', $this->bridge()->getUnavailableReason(4));
    }

    public function testItReportsNoUnavailabilityForAnEmptyOrUnknownType(): void
    {
        $values = ['', null, 'weird'];
        $this->config->method('getValue')->willReturnCallback(function () use (&$values) {
            return array_shift($values);
        });
        $this->modules = $this->createStub(ModuleManager::class);
        $this->modules->method('isEnabled')->willReturn(false);
        $bridge = $this->bridge();
        $this->assertNull($bridge->getUnavailableReason(1));
        $this->assertNull($bridge->getUnavailableReason(1));
        $this->assertNull($bridge->getUnavailableReason(1));
    }
}
