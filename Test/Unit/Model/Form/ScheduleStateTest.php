<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Form\Schedule;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class ScheduleStateTest extends TestCase
{
    private const OPEN = '2026-07-01T09:00:00Z';
    private const CLOSE = '2026-07-10T17:00:00Z';

    private function at(string $utc): int
    {
        return (new \DateTimeImmutable($utc))->getTimestamp();
    }

    public function testItReportsNotYetOpenBeforeTheOpenTime(): void
    {
        $this->assertSame(Schedule::NOT_YET, Schedule::state(self::OPEN, self::CLOSE, $this->at('2026-07-01T08:59:59Z')));
    }

    public function testItReportsClosedAfterTheCloseTime(): void
    {
        $this->assertSame(Schedule::CLOSED, Schedule::state(self::OPEN, self::CLOSE, $this->at('2026-07-10T17:00:00Z')));
        $this->assertSame(Schedule::CLOSED, Schedule::state('', self::CLOSE, $this->at('2026-07-11T00:00:00Z')));
    }

    public function testItIsOpenInsideTheWindowAndWhenNothingIsSet(): void
    {
        $this->assertSame(Schedule::OPEN, Schedule::state(self::OPEN, self::CLOSE, $this->at('2026-07-01T09:00:00Z')));
        $this->assertSame(Schedule::OPEN, Schedule::state(self::OPEN, '', $this->at('2030-01-01T00:00:00Z')));
        $this->assertSame(Schedule::OPEN, Schedule::state('', '', 0));
        $this->assertSame(Schedule::OPEN, Schedule::state('garbage', null, 0));
    }

    private function gate(string $now): ScheduleGate
    {
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn($this->at($now));
        return new ScheduleGate($clock);
    }

    private function form(array $settings): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn($settings);
        return $form;
    }

    public function testItGivesTheStoreViewMessageOfTheFormState(): void
    {
        $settings = ['open_from' => self::OPEN, 'close_at' => self::CLOSE, 'closed_message' => 'Gone', 'not_open_message' => 'Soon'];
        $this->assertSame(
            ['state' => 'not_yet', 'message' => 'Soon'],
            $this->gate('2026-06-01T00:00:00Z')->refusal($this->form($settings))
        );
        $this->assertSame(
            ['state' => 'closed', 'message' => 'Gone'],
            $this->gate('2026-08-01T00:00:00Z')->refusal($this->form($settings))
        );
        $this->assertNull($this->gate('2026-07-05T00:00:00Z')->refusal($this->form($settings)));
    }

    public function testItFallsBackToTheDefaultMessagesWhenNoneIsStored(): void
    {
        $settings = ['close_at' => self::CLOSE];
        $this->assertSame('This form is closed.', $this->gate('2027-01-01T00:00:00Z')->refusal($this->form($settings))['message']);
    }
}
