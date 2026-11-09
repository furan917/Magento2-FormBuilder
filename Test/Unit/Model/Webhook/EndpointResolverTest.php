<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointResolver;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class EndpointResolverTest extends TestCase
{
    private WebhookFixtures $fx;

    /** @param array<int, array<string, mixed>> $rows */
    private function resolver(array $rows): EndpointResolver
    {
        $this->fx = new WebhookFixtures($this->createStub(...));
        $this->fx->rows = $rows;
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturnCallback(function ($id): StoreInterface {
            $websites = [1 => 10, 2 => 10, 3 => 20];
            if (!isset($websites[$id])) {
                throw new NoSuchEntityException(__('No store'));
            }
            $store = $this->createStub(StoreInterface::class);
            $store->method('getWebsiteId')->willReturn($websites[$id]);

            return $store;
        });

        return new EndpointResolver($this->fx->repository, $stores);
    }

    /** @param \Furan\FormBuilder\Model\Webhook\EndpointRoute[] $views */
    private static function ids(array $views): array
    {
        return array_map(static fn ($v): int => $v->id(), $views);
    }

    public function testItFindsTheActiveEndpointsForASubmissionsOwnStoreFormAndEvent(): void
    {
        $resolver = $this->resolver([
            WebhookFixtures::row(['endpoint_id' => '1', 'scope_type' => 'store', 'scope_ids' => '[1]']),
            WebhookFixtures::row(['endpoint_id' => '2', 'scope_type' => 'website', 'scope_ids' => '[10]']),
            WebhookFixtures::row(['endpoint_id' => '3', 'scope_type' => 'website', 'scope_ids' => '[1]']),
            WebhookFixtures::row(['endpoint_id' => '4', 'scope_type' => 'store', 'scope_ids' => '[3]']),
            WebhookFixtures::row(['endpoint_id' => '5', 'form_ids' => '[9]']),
            WebhookFixtures::row(['endpoint_id' => '6', 'all_forms' => '1', 'form_ids' => '[]']),
            WebhookFixtures::row(['endpoint_id' => '7', 'events' => '["submission.updated"]']),
            WebhookFixtures::row(['endpoint_id' => '8', 'scope_type' => 'website', 'scope_ids' => '[20]']),
        ]);

        $this->assertSame([1, 2, 6], self::ids($resolver->match(5, 1, 'submission.created')));
        $this->assertSame([7], self::ids($resolver->match(5, 2, 'submission.updated')));
        $this->assertSame([4, 8], self::ids($resolver->match(5, 3, 'submission.created')));
    }

    public function testItMatchesNothingForAMissingOrDeletedStoreAndUnstoredEvents(): void
    {
        $resolver = $this->resolver([WebhookFixtures::row(['all_forms' => '1', 'form_ids' => '[]'])]);

        $this->assertSame([], $resolver->match(5, null, 'submission.created'));
        $this->assertSame([], $resolver->match(5, 0, 'submission.created'));
        $this->assertSame([], $resolver->match(5, 99, 'submission.created'));
        $this->assertSame([], $resolver->match(5, 1, 'webhook.test'));
    }

    public function testItReadsTheEndpointsOnlyOncePerRequest(): void
    {
        $resolver = $this->resolver([WebhookFixtures::row()]);

        $resolver->match(5, 1, 'submission.created');
        $resolver->match(6, 2, 'submission.created');

        $this->assertCount(1, $this->fx->selectedColumns);
    }

    public function testItMatchesEndpointsFromTheRoutingColumnsOnly(): void
    {
        $resolver = $this->resolver([WebhookFixtures::row()]);

        $resolver->match(5, 1, 'submission.created');

        $this->assertSame(
            [['endpoint_id', 'events', 'all_forms', 'form_ids', 'scope_type', 'scope_ids']],
            $this->fx->selectedColumns
        );
    }
}
