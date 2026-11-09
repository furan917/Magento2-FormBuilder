<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Submission;

use Furan\FormBuilder\Block\Adminhtml\Submission\View;
use Furan\FormBuilder\Model\Thread\Messages;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

class ViewThreadTest extends TestCase
{
    private function block(array $params, Messages $messages): View
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
        $block = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(View::class, 'messages'))->setValue($block, $messages);
        (new \ReflectionProperty(\Magento\Framework\View\Element\AbstractBlock::class, '_request'))->setValue($block, $request);

        return $block;
    }

    public function testItShowsTheLatestPageAndFlagsEarlierMessagesWithOneQuery(): void
    {
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('page')->with(5, null)
            ->willReturn(['messages' => [['message_id' => 9]], 'earlier' => true]);
        $block = $this->block(['id' => '5'], $messages);

        $this->assertSame([['message_id' => 9]], $block->getThread());
        $this->assertTrue($block->hasEarlierMessages());
        $this->assertNull($block->getBefore());
    }

    public function testItPagesBackWithADigitsOnlyBeforeParameter(): void
    {
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('page')->with(5, 300)
            ->willReturn(['messages' => [], 'earlier' => false]);
        $block = $this->block(['id' => '5', 'before' => '300'], $messages);

        $block->getThread();
        $this->assertSame(300, $block->getBefore());
        $this->assertNull($this->block(['id' => '5', 'before' => '3 OR 1'], $messages)->getBefore());
    }
}
