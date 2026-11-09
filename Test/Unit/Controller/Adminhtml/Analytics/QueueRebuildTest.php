<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Analytics;

use Furan\FormBuilder\Controller\Adminhtml\Analytics\QueueRebuild;
use Furan\FormBuilder\Model\Stats\RebuildQueue;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Framework\App\Action\HttpPostActionInterface;

class QueueRebuildTest extends ControllerTestCase
{
    public function testItQueuesARebuildForAFormOnAPostWithTheAnalyticsPermission(): void
    {
        $this->expectingRequest()->method('getParam')->with('form_id')->willReturn('5');
        $queue = $this->createMock(RebuildQueue::class);
        $queue->expects($this->once())->method('request')->with(5)->willReturn(1);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')->with(
            $this->callback(static fn ($m) => (string)$m === 'Statistics rebuild queued. It runs during the next off-peak window.')
        );
        $this->expectingRedirect()->expects($this->once())->method('setRefererOrBaseUrl')->willReturnSelf();

        $controller = new QueueRebuild($this->context, $queue);

        $this->assertSame('Furan_FormBuilder::analytics', QueueRebuild::ADMIN_RESOURCE);
        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testItQueuesEveryFormThatNeedsARebuildWhenNoFormIsGiven(): void
    {
        $this->expectingRequest()->method('getParam')->with('form_id')->willReturn(null);
        $queue = $this->createMock(RebuildQueue::class);
        $queue->expects($this->once())->method('request')->with(null)->willReturn(2);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->redirect->method('setRefererOrBaseUrl')->willReturnSelf();

        (new QueueRebuild($this->context, $queue))->execute();
    }

    public function testItNeverStartsARebuildFromTheAdminRequest(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $p) => (string)$p->getType(),
            (new \ReflectionMethod(QueueRebuild::class, '__construct'))->getParameters()
        );
        $this->assertNotContains(\Furan\FormBuilder\Model\Stats\Rebuilder::class, $types);
        $this->assertNotContains(\Furan\FormBuilder\Model\Stats\Folder::class, $types);
    }
}
