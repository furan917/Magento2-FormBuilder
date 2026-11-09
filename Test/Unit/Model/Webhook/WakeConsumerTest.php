<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryClaim;
use Furan\FormBuilder\Model\Webhook\DeliveryRunner;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use Furan\FormBuilder\Model\Webhook\WakeConsumer;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WakeConsumerTest extends TestCase
{
    /** @var array<int, int> */
    private array $ran = [];

    private function consumer(
        array $endpoints,
        bool $enabled = true,
        ?\Closure $clock = null,
        ?LoggerInterface $logger = null,
        ?\Closure $onRun = null
    ): WakeConsumer {
        $claim = $this->createStub(DeliveryClaim::class);
        $claim->method('endpointsOf')->willReturn($endpoints);
        $runner = $this->createStub(DeliveryRunner::class);
        $runner->method('run')->willReturnCallback(function (int $id) use ($onRun): bool {
            $this->ran[] = $id;
            if ($onRun !== null) {
                return $onRun($id);
            }
            return true;
        });
        $policy = $this->createStub(UrlPolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new WakeConsumer(
            $claim,
            $runner,
            $policy,
            new Json(),
            $logger ?? $this->createStub(LoggerInterface::class),
            $clock ?? static fn (): int => WebhookFixtures::NOW
        );
    }

    public function testItSendsOnlyTheWokenDeliveriesAndNeverThrowsFromTheConsumer(): void
    {
        $consumer = $this->consumer([5 => 1, 6 => 2], true, null, null, function (int $id): bool {
            if ($id === 5) {
                throw new \RuntimeException('boom');
            }
            return true;
        });

        $consumer->process('{"ids":[5,6,7]}');

        // 7 does not exist and is skipped; 5 throwing does not stop 6.
        $this->assertSame([5, 6], $this->ran);
    }

    public function testItDropsMalformedMessagesWithAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(4))->method('warning');
        $consumer = $this->consumer([1 => 1], true, null, $logger);

        $consumer->process('not json');
        $consumer->process('{"ids":"x"}');
        $consumer->process('{"ids":[]}');
        $consumer->process('{"ids":' . json_encode(range(1, 101)) . '}');

        $this->assertSame([], $this->ran);
    }

    public function testItStopsAtTheTimeBudgetAndLeavesTheRestToTheDrain(): void
    {
        $times = [WebhookFixtures::NOW, WebhookFixtures::NOW, WebhookFixtures::NOW + 51, WebhookFixtures::NOW + 51];
        $consumer = $this->consumer([1 => 1, 2 => 1, 3 => 1], true, static function () use (&$times): int {
            return count($times) > 1 ? array_shift($times) : $times[0];
        });

        $consumer->process('{"ids":[1,2,3]}');

        $this->assertSame([1], $this->ran);
    }

    public function testItClaimsAndSendsNothingWhileWebhooksAreDisabledInEnvPhp(): void
    {
        $claim = $this->createMock(DeliveryClaim::class);
        $claim->expects($this->never())->method('endpointsOf');
        $runner = $this->createMock(DeliveryRunner::class);
        $runner->expects($this->never())->method('run');
        $policy = $this->createStub(UrlPolicy::class);
        $policy->method('isEnabled')->willReturn(false);

        (new WakeConsumer($claim, $runner, $policy, new Json(), $this->createStub(LoggerInterface::class)))
            ->process('{"ids":[1]}');
    }
}
