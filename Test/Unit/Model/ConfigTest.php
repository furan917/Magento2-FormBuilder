<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Map\AttributionSanitizer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\File\Size;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&Stub $scopeConfig;
    private Size&Stub $fileSize;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->fileSize = $this->createStub(Size::class);
        $this->config = new Config($this->scopeConfig, $this->fileSize, new AttributionSanitizer());
    }

    /** @param array<string, mixed> $values path suffix => value */
    private function givenConfig(array $values): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig, $this->fileSize, new AttributionSanitizer());
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? null
        );
    }

    public function testItReturnsTheConfiguredRoutePrefix(): void
    {
        $this->givenConfig(['furan_formbuilder/general/route_prefix' => 'contact-us']);
        $this->assertSame('contact-us', $this->config->getRoutePrefix());
    }

    public function testItFallsBackToFormsWhenTheRoutePrefixIsInvalid(): void
    {
        foreach (['', '-bad', 'Has Space', 'UPPER', 'a/b', str_repeat('a', 33)] as $bad) {
            $scope = $this->createStub(ScopeConfigInterface::class);
            $scope->method('getValue')->willReturn($bad);
            $config = new Config($scope, $this->fileSize, new AttributionSanitizer());
            $this->assertSame('forms', $config->getRoutePrefix(), $bad);
        }
    }

    public function testItReturnsIntegerSpamSettingsWithAMinimumOfZero(): void
    {
        $this->givenConfig([
            'furan_formbuilder/spam/min_seconds' => '7',
            'furan_formbuilder/spam/rate_limit' => '-4',
            'furan_formbuilder/spam/rate_window_minutes' => 'abc',
        ]);
        $this->assertSame(7, $this->config->getMinSubmitSeconds());
        $this->assertSame(0, $this->config->getRateLimit());
        $this->assertSame(0, $this->config->getRateWindowMinutes());
    }

    public function testItCapsTheUploadSizeAtThePhpUploadLimit(): void
    {
        $this->givenConfig(['furan_formbuilder/uploads/max_size_mb' => '50']);
        $this->fileSize->method('getMaxFileSizeInMb')->willReturn(8);
        $this->assertSame(8, $this->config->getMaxUploadMb());
    }

    public function testItParsesAllowedExtensionsToALowercaseUniqueList(): void
    {
        $this->givenConfig(['furan_formbuilder/uploads/allowed_extensions' => ' PDF, jpg ,.png,pdf,,JPG ']);
        $this->assertSame(['pdf', 'jpg', 'png'], $this->config->getAllowedExtensions());
    }

    public function testItStripsHardBlockedExtensionsFromTheAllowedList(): void
    {
        $this->givenConfig(['furan_formbuilder/uploads/allowed_extensions' => 'pdf,PHP,svg,phtml,html,txt']);
        $this->assertSame(['pdf', 'txt'], $this->config->getAllowedExtensions());
    }

    public function testItReturnsOnlyValidTrustedProxyAddressesAndRanges(): void
    {
        $this->givenConfig([
            'furan_formbuilder/spam/trusted_proxies' => "10.0.0.0/8, 192.0.2.1\nfd00::/8 junk 1.2.3.4/40 ::1/129 ,10.0.0.0/8",
        ]);
        $this->assertSame(['10.0.0.0/8', '192.0.2.1', 'fd00::/8'], $this->config->getTrustedProxies());
        $this->givenConfig([]);
        $this->assertSame([], $this->config->getTrustedProxies());
    }

    public function testItReturnsTheUploadBudgetInBytes(): void
    {
        $this->givenConfig(['furan_formbuilder/uploads/form_budget_mb' => '200']);
        $this->assertSame(200 * 1048576, $this->config->getUploadBudgetBytes());
        $this->givenConfig(['furan_formbuilder/uploads/form_budget_mb' => '0']);
        $this->assertSame(0, $this->config->getUploadBudgetBytes());
    }

    public function testItReturnsRetentionDaysAndDraftTtlHours(): void
    {
        $this->givenConfig([
            'furan_formbuilder/privacy/retention_days' => '90',
            'furan_formbuilder/privacy/draft_ttl_hours' => '12',
        ]);
        $this->assertSame(90, $this->config->getRetentionDays());
        $this->assertSame(12, $this->config->getDraftTtlHours());
    }

    public function testItReturnsTheSenderIdentityDefaultingToGeneral(): void
    {
        $this->givenConfig([]);
        $this->assertSame('general', $this->config->getSenderIdentity());

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn('support');
        $this->assertSame('support', (new Config($scope, $this->fileSize, new AttributionSanitizer()))->getSenderIdentity());
    }

    public function testItFallsBackToTheModuleTemplateWhenNoneIsChosen(): void
    {
        $this->givenConfig([]);
        $this->assertSame('furan_formbuilder_email_notification_template', $this->config->getEmailTemplate('notification'));

        $this->givenConfig(['furan_formbuilder/email/claim_template' => '  ']);
        $this->assertSame('furan_formbuilder_email_claim_template', $this->config->getEmailTemplate('claim', 2));

        $this->givenConfig(['furan_formbuilder/email/claim_template' => '17']);
        $this->assertSame('17', $this->config->getEmailTemplate('claim', 2));
    }

    public function testItReturnsTheAccountDeletionBehaviourDefaultingToAnonymise(): void
    {
        foreach (['keep', 'delete', 'anonymise'] as $value) {
            $this->givenConfig(['furan_formbuilder/privacy/on_customer_delete' => $value]);
            $this->assertSame($value, $this->config->getAccountDeletionBehaviour());
        }
        $this->givenConfig([]);
        $this->assertSame('anonymise', $this->config->getAccountDeletionBehaviour());
    }

    public function testItReturnsAnonymiseForAnUnknownAccountDeletionBehaviourValue(): void
    {
        $this->givenConfig(['furan_formbuilder/privacy/on_customer_delete' => 'purge']);
        $this->assertSame('anonymise', $this->config->getAccountDeletionBehaviour());
    }

    public function testItReturnsTheServerDraftLifetimeInDaysDefaultingTo30(): void
    {
        $this->givenConfig([]);
        $this->assertSame(30, $this->config->getServerDraftDays());
        $this->givenConfig(['furan_formbuilder/customers/draft_days' => '7']);
        $this->assertSame(7, $this->config->getServerDraftDays());
        $this->givenConfig(['furan_formbuilder/customers/draft_days' => '0']);
        $this->assertSame(30, $this->config->getServerDraftDays());
    }

    public function testItReadsTheClaimDraftSyncAndAnalyticsFlags(): void
    {
        $this->givenConfig([
            'furan_formbuilder/customers/claim_enabled' => '0',
            'furan_formbuilder/customers/draft_sync' => '1',
            'furan_formbuilder/analytics/enabled' => '0',
        ]);
        $this->assertFalse($this->config->isClaimEnabled());
        $this->assertTrue($this->config->isDraftSyncEnabled());
        $this->assertFalse($this->config->isAnalyticsEnabled());
    }

    public function testItReadsTheCustomerReplySettings(): void
    {
        $this->givenConfig([
            'furan_formbuilder/customers/replies_enabled' => '0',
            'furan_formbuilder/customers/guest_reply_links' => '1',
        ]);
        $this->assertFalse($this->config->isCustomerRepliesEnabled(2));
        $this->assertTrue($this->config->isGuestReplyLinksEnabled(2));
    }

    public function testItReadsTheThreadMessageLimitWithZeroAsUnlimited(): void
    {
        $this->givenConfig([]);
        $this->assertSame(200, $this->config->getMaxThreadMessages());
        $this->givenConfig(['furan_formbuilder/customers/max_thread_messages' => '50']);
        $this->assertSame(50, $this->config->getMaxThreadMessages());
        $this->givenConfig(['furan_formbuilder/customers/max_thread_messages' => '0']);
        $this->assertSame(0, $this->config->getMaxThreadMessages());
        $this->givenConfig(['furan_formbuilder/customers/max_thread_messages' => '-3']);
        $this->assertSame(0, $this->config->getMaxThreadMessages());
    }

    public function testItFallsBackToTheDefaultTileUrlWhenTheStoredOneIsInvalid(): void
    {
        $this->givenConfig(['furan_formbuilder/maps/tile_url' => 'https://t.test/{z}/{x}/{y}/{bogus}.png']);
        $this->assertSame('https://tile.openstreetmap.org/{z}/{x}/{y}.png', $this->config->getTileUrl());

        $this->givenConfig(['furan_formbuilder/maps/tile_url' => 'https://t.test/{z}/{x}/{y}.png']);
        $this->assertSame('https://t.test/{z}/{x}/{y}.png', $this->config->getTileUrl());
    }

    public function testItReturnsTheOpenStreetMapProviderByDefault(): void
    {
        $this->givenConfig([]);
        $this->assertSame('osm', $this->config->getMapProvider());

        $this->givenConfig(['furan_formbuilder/maps/provider' => 'bing']);
        $this->assertSame('osm', $this->config->getMapProvider());

        $this->givenConfig(['furan_formbuilder/maps/provider' => 'google']);
        $this->assertSame('google', $this->config->getMapProvider());
    }

    public function testItReturnsTheDecryptedGoogleKeyOnlyWhenTheGoogleProviderIsSelected(): void
    {
        $this->givenConfig([
            'furan_formbuilder/maps/provider' => 'osm',
            'furan_formbuilder/maps/google_api_key' => 'KEY123',
        ]);
        $this->assertSame('', $this->config->getGoogleApiKey());

        $this->givenConfig([
            'furan_formbuilder/maps/provider' => 'google',
            'furan_formbuilder/maps/google_api_key' => ' KEY123 ',
        ]);
        $this->assertSame('KEY123', $this->config->getGoogleApiKey());

        $this->givenConfig(['furan_formbuilder/maps/provider' => 'google']);
        $this->assertSame('', $this->config->getGoogleApiKey());
    }

    public function testItSanitisesTheAttributionAgainWhenReadingItFromConfig(): void
    {
        $this->givenConfig([
            'furan_formbuilder/maps/attribution' => '<img src=x onerror=alert(1)>'
                . '<a href="https://a.test" onclick="x()">A</a><a href="javascript:x">J</a>',
        ]);
        $this->assertSame(
            '<a href="https://a.test">A</a>J',
            $this->config->getAttribution()
        );
    }

    public function testItClampsMaxZoomAndDefaultsAnEmptySearchEndpoint(): void
    {
        $this->givenConfig(['furan_formbuilder/maps/max_zoom' => '99']);
        $this->assertSame(22, $this->config->getMaxZoom());
        $this->givenConfig(['furan_formbuilder/maps/max_zoom' => '0']);
        $this->assertSame(1, $this->config->getMaxZoom());
        $this->givenConfig([]);
        $this->assertSame(19, $this->config->getMaxZoom());

        $this->givenConfig(['furan_formbuilder/maps/search_endpoint' => '  ']);
        $this->assertSame('https://nominatim.openstreetmap.org/search', $this->config->getMapSearchEndpoint());
        $this->givenConfig(['furan_formbuilder/maps/search_endpoint' => 'https://geo.test/search']);
        $this->assertSame('https://geo.test/search', $this->config->getMapSearchEndpoint());
    }

    public function testItReadsTheStorefrontSwitchPerStore(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn (string $path, string $type, ?int $store): ?string
                => $path === 'furan_formbuilder/general/enabled' ? ($store === 2 ? '0' : '1') : null
        );
        $config = new Config($scope, $this->fileSize, new AttributionSanitizer());

        $this->assertTrue($config->isEnabled(1));
        $this->assertFalse($config->isEnabled(2));
    }

    public function testItReturnsAValidatedReplyToAddressOrEmpty(): void
    {
        $this->givenConfig([]);
        $this->assertSame('', $this->config->getReplyTo(1));
        $this->givenConfig(['furan_formbuilder/email/reply_to' => '  help@shop.example  ']);
        $this->assertSame('help@shop.example', $this->config->getReplyTo(1));
        foreach (["a@b.co\r\nBcc: x@y.co", 'not-an-email'] as $bad) {
            $this->givenConfig(['furan_formbuilder/email/reply_to' => $bad]);
            $this->assertSame('', $this->config->getReplyTo(1), $bad);
        }
    }
}
