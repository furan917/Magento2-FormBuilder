<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Newsletter;

use Furan\FormBuilder\Model\Newsletter\NewsletterBridge;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NewsletterBridgeTest extends TestCase
{
    public function testItOffersTheNewsletterFieldWhenTheNewsletterModuleIsEnabled(): void
    {
        $bridge = $this->bridge(true, true);

        $this->assertTrue($bridge->isAvailable());
    }

    public function testItIsUnavailableWhenTheModuleIsDisabledWithoutTouchingConfig(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects($this->never())->method('isSetFlag');

        $this->assertFalse($this->bridge(false, true, scope: $scope)->isAvailable());
        $this->assertFalse($this->bridge(false, true, scope: $scope)->isAvailable(1));
    }

    public function testItIsUnavailableWhenTheModuleIsEnabledButItsClassesAreMissing(): void
    {
        $this->assertFalse($this->bridge(true, true, ['Magento\\Nope\\Missing'])->isAvailable());
    }

    public function testItChecksTheStoreFlagOnlyWhenAStoreIsGiven(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects($this->once())->method('isSetFlag')
            ->with('newsletter/general/active', 'store', 3)->willReturn(false);

        $bridge = $this->bridge(true, true, scope: $scope);

        $this->assertTrue($bridge->isAvailable());
        $this->assertFalse($bridge->isAvailable(3));
    }

    public function testItSubscribesThroughTheSubscriptionManagerOnlyWhenAvailableAndAllowed(): void
    {
        $manager = new class {
            public array $calls = [];

            public function subscribe(string $email, int $storeId): void
            {
                $this->calls[] = ['guest', $email, $storeId];
            }

            public function subscribeCustomer(int $customerId, int $storeId): void
            {
                $this->calls[] = ['customer', $customerId, $storeId];
            }
        };
        $objects = $this->createStub(\Magento\Framework\ObjectManagerInterface::class);
        $objects->method('get')->willReturnCallback(fn (string $class): object => $class === 'Magento\\Newsletter\\Model\\SubscriptionManagerInterface'
            ? $manager
            : $this->subscriberFactory(0));
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path === 'newsletter/general/active'
        );
        $bridge = $this->bridge(true, true, scope: $scope, objects: $objects);

        $this->assertFalse($bridge->allowsGuest(1));
        $this->assertFalse($bridge->subscribeGuest('a@example.com', 1));
        $this->assertTrue($bridge->subscribeCustomer(5, 1));
        $this->assertSame([['customer', 5, 1]], $manager->calls);

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);
        $bridge = $this->bridge(true, true, scope: $scope, objects: $objects);
        $this->assertTrue($bridge->subscribeGuest('a@example.com', 3));
        $this->assertSame(['guest', 'a@example.com', 3], $manager->calls[1]);

        $this->assertFalse($this->bridge(false, true, objects: $objects)->subscribeCustomer(5, 1));
        $this->assertCount(2, $manager->calls);
    }

    public function testItNeverReSubscribesAGuestEmailThatHasUnsubscribed(): void
    {
        $manager = $this->recordingManager();
        $bridge = $this->guestBridge($manager, existingSubscriberId: 9);

        $this->assertFalse($bridge->subscribeGuest('gone@example.com', 1));
        $this->assertSame([], $manager->calls);
    }

    public function testItNeverSubscribesACustomerAccountEmailThroughTheGuestPath(): void
    {
        $manager = $this->recordingManager();
        $bridge = $this->guestBridge($manager, customerExists: true);

        $this->assertFalse($bridge->subscribeGuest('member@example.com', 1));
        $this->assertSame([], $manager->calls);
    }

    public function testItSubscribesAGuestEmailWithNoSubscriberRowAndNoAccount(): void
    {
        $manager = $this->recordingManager();
        $bridge = $this->guestBridge($manager);

        $this->assertTrue($bridge->subscribeGuest('new@example.com', 1));
        $this->assertSame([['guest', 'new@example.com', 1]], $manager->calls);
    }

    public function testItSubscribesThroughTheBridgeWhenTheNewsletterModuleIsEnabled(): void
    {
        $manager = $this->recordingManager();
        $objects = $this->createStub(\Magento\Framework\ObjectManagerInterface::class);
        $objects->method('get')->willReturn($manager);

        $this->assertTrue($this->bridge(true, true, objects: $objects)->subscribeCustomer(5, 1));
        $this->assertSame([['customer', 5, 1]], $manager->calls);
    }

    public function testItReportsTheNewsletterFieldUnavailableWhenTheNewsletterModuleIsDisabled(): void
    {
        $this->assertFalse($this->bridge(false, true)->isAvailable());
    }

    public function testItNeverResolvesANewsletterClassWhileTheModuleIsUnavailable(): void
    {
        $objects = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objects->expects($this->never())->method('get');
        $bridge = $this->bridge(false, true, objects: $objects);

        $this->assertFalse($bridge->subscribeCustomer(5, 1));
        $this->assertFalse($bridge->subscribeGuest('a@example.com', 1));
    }

    private function recordingManager(): object
    {
        return new class {
            public array $calls = [];

            public function subscribe(string $email, int $storeId): void
            {
                $this->calls[] = ['guest', $email, $storeId];
            }

            public function subscribeCustomer(int $customerId, int $storeId): void
            {
                $this->calls[] = ['customer', $customerId, $storeId];
            }
        };
    }

    private function subscriberFactory(int $existingSubscriberId): object
    {
        $subscriber = new class ($existingSubscriberId) {
            public function __construct(private readonly int $id)
            {
            }

            public function loadBySubscriberEmail(string $email, int $websiteId): self
            {
                return $this;
            }

            public function getId(): int
            {
                return $this->id;
            }
        };

        return new class ($subscriber) {
            public function __construct(private readonly object $subscriber)
            {
            }

            public function create(): object
            {
                return $this->subscriber;
            }
        };
    }

    private function guestBridge(object $manager, int $existingSubscriberId = 0, bool $customerExists = false): NewsletterBridge
    {
        $objects = $this->createStub(\Magento\Framework\ObjectManagerInterface::class);
        $objects->method('get')->willReturnCallback(fn (string $class): object => match ($class) {
            'Magento\\Newsletter\\Model\\SubscriptionManagerInterface' => $manager,
            'Magento\\Newsletter\\Model\\SubscriberFactory' => $this->subscriberFactory($existingSubscriberId),
        });
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);

        return $this->bridge(true, true, scope: $scope, objects: $objects, customerExists: $customerExists);
    }

    private function bridge(bool $enabled, bool $active, array $classes = [], ?ScopeConfigInterface $scope = null, ?\Magento\Framework\ObjectManagerInterface $objects = null, bool $customerExists = false): NewsletterBridge
    {
        $manager = $this->createMock(ModuleManager::class);
        $manager->method('isEnabled')->with('Magento_Newsletter')->willReturn($enabled);
        if ($scope === null) {
            $scope = $this->createStub(ScopeConfigInterface::class);
            $scope->method('isSetFlag')->willReturn($active);
        }
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        if ($customerExists) {
            $customers->method('get')->willReturn($this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class));
        } else {
            $customers->method('get')->willThrowException(new NoSuchEntityException());
        }
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(4);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new NewsletterBridge($manager, $scope, $objects ?? $this->createStub(\Magento\Framework\ObjectManagerInterface::class), $customers, $stores, $classes);
    }
}
