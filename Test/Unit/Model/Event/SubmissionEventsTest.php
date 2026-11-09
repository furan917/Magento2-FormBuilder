<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Event;

use Furan\FormBuilder\Model\Event\SubmissionEvents;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubmissionEventsTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private ManagerInterface&Stub $manager;
    private LoggerInterface&Stub $logger;

    private function events(): SubmissionEvents
    {
        $this->manager = $this->double('manager', ManagerInterface::class);
        $this->logger = $this->double('logger', LoggerInterface::class);

        return new SubmissionEvents($this->manager, $this->logger);
    }

    public function testItDispatchesStatusChangedWithTheOldAndNewStatus(): void
    {
        $this->asserted = ['manager'];
        $events = $this->events();
        $this->manager->expects($this->once())->method('dispatch')->with(
            'furan_formbuilder_submission_status_changed',
            ['submission_id' => 5, 'form_id' => 7, 'old_status' => 0, 'new_status' => 2, 'source' => 'admin']
        );

        $events->statusChanged(5, 7, 0, 2, 'admin');
    }

    public function testItDispatchesThreadMessageAdded(): void
    {
        $this->asserted = ['manager'];
        $events = $this->events();
        $this->manager->expects($this->once())->method('dispatch')->with(
            'furan_formbuilder_thread_message_added',
            ['submission_id' => 5, 'form_id' => 7, 'author' => 'guest', 'message_id' => 31]
        );

        $events->threadMessageAdded(5, 7, 'guest', 31);
    }

    public function testItLogsObserverFailuresInsteadOfThrowing(): void
    {
        $this->asserted = ['logger'];
        $events = $this->events();
        $this->manager->method('dispatch')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->exactly(2))->method('error');

        $events->statusChanged(5, 7, 0, 2, 'system');
        $events->threadMessageAdded(5, 7, 'admin', 1);
    }
}
