<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryPolicy;
use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\Outcome;
use Furan\FormBuilder\Model\Webhook\RandomFraction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeliveryPolicyTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const STATUS_NAMES = [
        'pending' => DeliveryStatus::PENDING,
        'succeeded' => DeliveryStatus::SUCCEEDED,
        'failed' => DeliveryStatus::FAILED,
        'blocked' => DeliveryStatus::BLOCKED,
    ];

    /** @return array<string, array{array<string, mixed>}> */
    public static function matrixRows(): array
    {
        $rows = [];
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/webhook-delivery-policy.json'), true);
        foreach ($fixture['matrix'] as $row) {
            $rows[$row['name']] = [$row];
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('matrixRows')]
    public function testItMapsEveryOutcomeInTheStatusMatrixToItsDecision(array $row): void
    {
        $outcome = new Outcome($row['status'], $row['errorClass'], $row['bodyTruncated'], null);
        $decision = $this->policy()->decide($outcome, 1, $this->utc(self::NOW - 60), $this->state(), self::NOW);

        $expect = $row['expect'];
        $this->assertSame(self::STATUS_NAMES[$expect['status']], $decision->status);
        $this->assertSame($expect['errorClass'], $decision->errorClass);
        $this->assertSame($expect['disable'], $decision->disableEndpoint);
        $this->assertSame($expect['reset'], $decision->resetFailures);
        $this->assertSame($expect['retry'], $decision->nextAttemptAt !== null);
    }

    public function testItSchedulesTheNextAttemptWithBoundedJitter(): void
    {
        $delays = [300, 1800, 7200, 18000, 36000, 50400, 72000];
        $outcome = new Outcome(500, null, false, null);
        foreach ($delays as $index => $delay) {
            foreach ([[0.0, 0.8], [0.5, 1.0], [1.0, 1.2]] as [$fraction, $factor]) {
                $decision = $this->policy($fraction)->decide(
                    $outcome,
                    $index + 1,
                    $this->utc(self::NOW - 60),
                    $this->state(),
                    self::NOW
                );
                $this->assertSame(DeliveryStatus::PENDING, $decision->status);
                $this->assertSame($this->utc(self::NOW + (int)round($delay * $factor)), $decision->nextAttemptAt);
            }
        }
    }

    /** @return array<string, array{string, int, int}> */
    public static function retryAfterCases(): array
    {
        $future = gmdate('D, d M Y H:i:s', self::NOW + 3600) . ' GMT';
        $past = gmdate('D, d M Y H:i:s', self::NOW - 3600) . ' GMT';
        $far = gmdate('D, d M Y H:i:s', self::NOW + 86400 * 3650) . ' GMT';
        return [
            'seconds above the schedule' => ['3600', 1, 3600],
            'seconds below the schedule' => ['120', 1, 300],
            'clamped to six hours' => ['999999', 1, 21600],
            'ten years of seconds' => ['315360000', 1, 21600],
            'absurdly long digits' => [str_repeat('9', 40), 1, 21600],
            'http date' => [$future, 1, 3600],
            'http date in the past' => [$past, 1, 300],
            'http date ten years away' => [$far, 1, 21600],
            'negative' => ['-5', 1, 300],
            'garbage' => ['soon', 1, 300],
            'fraction' => ['1.5', 1, 300],
            'empty' => ['', 1, 300],
            'never earlier than a long schedule' => ['3600', 7, 72000],
        ];
    }

    #[DataProvider('retryAfterCases')]
    public function testItHonoursRetryAfterUpToSixHoursAndIgnoresInvalidValues(
        string $header,
        int $attempt,
        int $expectedDelay
    ): void {
        $decision = $this->policy()->decide(
            new Outcome(429, null, false, $header),
            $attempt,
            $this->utc(self::NOW - 60),
            $this->state(),
            self::NOW
        );
        $this->assertSame($this->utc(self::NOW + $expectedDelay), $decision->nextAttemptAt);
    }

    public function testItGivesUpAfterTheLastAttemptOrTheThreeDayWindow(): void
    {
        $outcome = new Outcome(500, null, false, null);
        $fresh = $this->utc(self::NOW - 60);

        $last = $this->policy()->decide($outcome, 8, $fresh, $this->state(), self::NOW);
        $this->assertSame(DeliveryStatus::FAILED, $last->status);
        $this->assertNull($last->nextAttemptAt);
        $this->assertSame(DeliveryStatus::ERROR_HTTP, $last->errorClass);

        $seventh = $this->policy()->decide($outcome, 7, $fresh, $this->state(), self::NOW);
        $this->assertSame(DeliveryStatus::PENDING, $seventh->status);

        $justInside = $this->utc(self::NOW - 3 * 86400 + 1);
        $this->assertSame(
            DeliveryStatus::PENDING,
            $this->policy()->decide($outcome, 2, $justInside, $this->state(), self::NOW)->status
        );
        $exactly = $this->utc(self::NOW - 3 * 86400);
        $over = $this->policy()->decide($outcome, 2, $exactly, $this->state(), self::NOW);
        $this->assertSame(DeliveryStatus::FAILED, $over->status);
        $this->assertNull($over->nextAttemptAt);
    }

    public function testItExpiresAClaimedRowWhoseWindowAlreadyEndedWithoutSending(): void
    {
        $policy = $this->policy();
        $this->assertNull($policy->expiry($this->utc(self::NOW - 3 * 86400 + 1), self::NOW));
        $decision = $policy->expiry($this->utc(self::NOW - 3 * 86400), self::NOW);
        $this->assertNotNull($decision);
        $this->assertSame(DeliveryStatus::FAILED, $decision->status);
        $this->assertSame(DeliveryStatus::ERROR_EXPIRED, $decision->errorClass);
        $this->assertNull($decision->nextAttemptAt);
        $this->assertNull($decision->disableEndpoint);
        $this->assertFalse($decision->resetFailures);
    }

    public function testItOpensTheCircuitAfterFiveConsecutiveFailures(): void
    {
        $outcome = new Outcome(503, null, false, null);
        $created = $this->utc(self::NOW - 60);

        $fourth = $this->policy()->decide($outcome, 1, $created, $this->state(null, 3), self::NOW);
        $this->assertNull($fourth->openCircuitUntil);

        $fifth = $this->policy()->decide($outcome, 1, $created, $this->state(null, 4), self::NOW);
        $this->assertSame($this->utc(self::NOW + 300), $fifth->openCircuitUntil);
        $this->assertSame($fifth->nextAttemptAt, $fifth->openCircuitUntil);

        $later = $this->policy()->decide($outcome, 3, $created, $this->state(null, 9), self::NOW);
        $this->assertSame($this->utc(self::NOW + 7200), $later->openCircuitUntil);

        $exhausted = $this->policy()->decide($outcome, 8, $created, $this->state(null, 4), self::NOW);
        $this->assertSame(DeliveryStatus::FAILED, $exhausted->status);
        $this->assertSame($this->utc(self::NOW + 72000), $exhausted->openCircuitUntil);

        $success = $this->policy()->decide(new Outcome(200, null, false, null), 1, $created, $this->state(null, 9), self::NOW);
        $this->assertNull($success->openCircuitUntil);
        $this->assertTrue($success->resetFailures);

        $blocked = $this->policy()->decide(new Outcome(0, 'blocked', false, null), 1, $created, $this->state(null, 9), self::NOW);
        $this->assertNull($blocked->openCircuitUntil);
    }

    public function testItDisablesTheEndpointAfterThreeDaysOfFailuresOrOn410(): void
    {
        $created = $this->utc(self::NOW - 60);
        $failing = new Outcome(500, null, false, null);
        $old = $this->state($this->utc(self::NOW - 3 * 86400), 20);
        $young = $this->state($this->utc(self::NOW - 3 * 86400 + 1), 20);

        $disabled = $this->policy()->decide($failing, 2, $created, $old, self::NOW);
        $this->assertSame('failing', $disabled->disableEndpoint);
        $this->assertSame(DeliveryStatus::PENDING, $disabled->status);

        $final = $this->policy()->decide($failing, 8, $created, $old, self::NOW);
        $this->assertSame('failing', $final->disableEndpoint);

        $this->assertNull($this->policy()->decide($failing, 2, $created, $young, self::NOW)->disableEndpoint);
        $this->assertNull($this->policy()->decide($failing, 2, $created, $this->state(), self::NOW)->disableEndpoint);
        $this->assertNull(
            $this->policy()->decide(new Outcome(200, null, false, null), 2, $created, $old, self::NOW)->disableEndpoint
        );
        $this->assertNull(
            $this->policy()->decide(new Outcome(0, 'blocked', false, null), 2, $created, $old, self::NOW)->disableEndpoint
        );

        $gone = $this->policy()->decide(new Outcome(410, null, false, null), 1, $created, $this->state(), self::NOW);
        $this->assertSame('gone', $gone->disableEndpoint);
        $this->assertSame(DeliveryStatus::ERROR_ENDPOINT_GONE, $gone->errorClass);
    }

    public function testItExposesTheClampedRetryAfterForTheAttemptLog(): void
    {
        $policy = $this->policy();

        $this->assertSame(60, $policy->retryAfter('60', self::NOW));
        $this->assertSame(21600, $policy->retryAfter('999999999999', self::NOW));
        $this->assertSame(21600, $policy->retryAfter(gmdate('D, d M Y H:i:s \\G\\M\\T', self::NOW + 90000), self::NOW));
        $this->assertNull($policy->retryAfter('soon', self::NOW));
        $this->assertNull($policy->retryAfter(null, self::NOW));
    }

    private function policy(float $fraction = 0.5): DeliveryPolicy
    {
        $random = $this->createStub(RandomFraction::class);
        $random->method('next')->willReturn($fraction);
        return new DeliveryPolicy($random);
    }

    /** @return array{failing_since: ?string, consecutive_failures: int} */
    private function state(?string $failingSince = null, int $failures = 0): array
    {
        return ['failing_since' => $failingSince, 'consecutive_failures' => $failures];
    }

    private function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
