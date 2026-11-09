<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\SettingsNormalizer;
use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Test\Unit\Helper\ArraySettingsContributor;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\TestCase;

class SettingsNormalizerScheduleTest extends TestCase
{
    private const SCHEMA = ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'items' => []]]];

    private function normalize(array $raw, array $stored = [], string $default = 'UTC'): array
    {
        $validator = $this->createStub(RedirectValidator::class);
        $validator->method('isAllowed')->willReturn(true);
        $normalizer = new SettingsNormalizer(
            $validator,
            FieldPools::builtIn(),
            new SettingValues($validator),
            ArraySettingsContributor::pool(),
            ConditionNormalizers::builtIn([], null)
        );
        return $normalizer->normalize($raw, self::SCHEMA, $stored, $default);
    }

    private function errors(array $raw, array $stored = [], string $default = 'UTC'): array
    {
        try {
            $this->normalize($raw, $stored, $default);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        return [];
    }

    public function testItStoresOpenFromAndCloseAtAsUtc(): void
    {
        $summer = $this->normalize([
            'open_from' => '2026-07-01T10:00', 'close_at' => '2026-12-01T10:00', 'schedule_timezone' => 'Europe/London',
        ]);

        $this->assertSame('2026-07-01T09:00:00Z', $summer['open_from']);
        $this->assertSame('2026-12-01T10:00:00Z', $summer['close_at']);
        $this->assertSame('Europe/London', $summer['schedule_timezone']);
    }

    public function testItRejectsACloseTimeBeforeTheOpenTime(): void
    {
        $this->assertNotSame([], $this->errors([
            'open_from' => '2026-07-02T10:00', 'close_at' => '2026-07-01T10:00', 'schedule_timezone' => 'UTC',
        ]));
        $this->assertNotSame([], $this->errors([
            'open_from' => '2026-07-01T10:00', 'close_at' => '2026-07-01T10:00', 'schedule_timezone' => 'UTC',
        ]));
        // Compared as instants: later wall-clock time in an earlier-offset zone is still before.
        $this->assertNotSame([], $this->errors([
            'open_from' => '2026-07-01T10:00:00+00:00', 'close_at' => '2026-07-01T11:00:00+05:00',
        ]));
        $this->assertSame([], $this->errors(['close_at' => '2026-07-01T10:00', 'schedule_timezone' => 'UTC']));
    }

    public function testItRejectsInvalidTimeText(): void
    {
        foreach (['tomorrow', '2026-02-31T10:00', '2026-07-01', '2026-07-01 10:00'] as $bad) {
            $this->assertNotSame([], $this->errors(['open_from' => $bad, 'schedule_timezone' => 'UTC']), $bad);
        }
    }

    public function testItKeepsAnImportedUtcInstantUnchanged(): void
    {
        $out = $this->normalize(
            ['open_from' => '2026-07-01T09:00:00Z', 'close_at' => '2026-07-01T12:00:00+02:00', 'schedule_timezone' => 'America/New_York'],
            [],
            'Asia/Tokyo'
        );

        $this->assertSame('2026-07-01T09:00:00Z', $out['open_from']);
        $this->assertSame('2026-07-01T10:00:00Z', $out['close_at']);
        $this->assertSame('America/New_York', $out['schedule_timezone']);
    }

    public function testItKeepsTheInstantWhenStoreViewsChangeAndTheTimeWasNotEdited(): void
    {
        $raw = ['open_from' => '2026-07-01T09:00:00Z', 'schedule_timezone' => 'Europe/London'];

        $before = $this->normalize($raw, [], 'Europe/London');
        $after = $this->normalize($raw, $before, 'Asia/Tokyo');

        $this->assertSame($before['open_from'], $after['open_from']);
        $this->assertSame('Europe/London', $after['schedule_timezone']);
    }

    public function testItConvertsTheEnteredTimesUsingTheTimezoneTheAdminPicked(): void
    {
        $out = $this->normalize(['open_from' => '2026-07-01T10:00', 'schedule_timezone' => 'Asia/Tokyo'], [], 'Europe/London');

        $this->assertSame('2026-07-01T01:00:00Z', $out['open_from']);
    }

    public function testItDefaultsTheTimezoneToTheSingleStoreViewsZoneWhenNoneIsStored(): void
    {
        $out = $this->normalize(['open_from' => '2026-01-01T10:00'], [], 'America/New_York');

        $this->assertSame('2026-01-01T15:00:00Z', $out['open_from']);
        $this->assertSame('America/New_York', $out['schedule_timezone']);
    }

    public function testItNeverReplacesAStoredTimezoneWhenStoreViewsChange(): void
    {
        $out = $this->normalize(
            ['open_from' => '2026-07-01T10:00'],
            ['schedule_timezone' => 'Europe/London', 'open_from' => '2026-07-01T09:00:00Z'],
            'America/New_York'
        );

        $this->assertSame('Europe/London', $out['schedule_timezone']);
        $this->assertSame('2026-07-01T09:00:00Z', $out['open_from']);
    }

    public function testItRejectsAnUnknownTimezoneAnOffsetOrAnAbbreviation(): void
    {
        foreach (['Mars/Base', '+05:00', 'EST', 'europe/london', 'UTC+1'] as $bad) {
            $this->assertNotSame([], $this->errors(['open_from' => '2026-07-01T10:00', 'schedule_timezone' => $bad]), $bad);
        }
    }

    public function testItStoresAnEmptyTimezoneWhenNoTimesAreSet(): void
    {
        $out = $this->normalize(['schedule_timezone' => 'Europe/London']);

        $this->assertSame('', $out['schedule_timezone']);
        $this->assertSame('', $out['open_from']);
        $this->assertSame('', $out['close_at']);
    }

    public function testItAcceptsAStoresLegacyTimezoneIdAsTheDefault(): void
    {
        $this->assertNotContains('Asia/Calcutta', \DateTimeZone::listIdentifiers());

        $out = $this->normalize(['open_from' => '2026-07-01T10:00', 'schedule_timezone' => 'Asia/Calcutta'], [], 'Asia/Calcutta');
        $this->assertSame('2026-07-01T04:30:00Z', $out['open_from']);

        $unchanged = $this->normalize(
            ['open_from' => '2026-07-01T10:00', 'schedule_timezone' => 'Asia/Calcutta'],
            ['schedule_timezone' => 'Asia/Calcutta'],
            'UTC'
        );
        $this->assertSame('Asia/Calcutta', $unchanged['schedule_timezone']);
        $this->assertNotSame([], $this->errors(['open_from' => '2026-07-01T10:00', 'schedule_timezone' => 'Asia/Calcutta']));
    }

    public function testItDefaultsTheClosedAndNotOpenMessages(): void
    {
        $out = $this->normalize([]);

        $this->assertSame('This form is closed.', $out['closed_message']);
        $this->assertSame('This form is not open yet.', $out['not_open_message']);
        $custom = $this->normalize(['closed_message' => '<b>Gone</b>', 'not_open_message' => 'Soon']);
        $this->assertSame('Gone', $custom['closed_message']);
        $this->assertSame('Soon', $custom['not_open_message']);
    }
}
