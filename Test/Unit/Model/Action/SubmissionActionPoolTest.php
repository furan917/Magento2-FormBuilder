<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Action;

use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Api\SubmissionActionInterface;
use Furan\FormBuilder\Model\Action\SubmissionActionPool;
use PHPUnit\Framework\TestCase;

class SubmissionActionPoolTest extends TestCase
{
    public function testItListsEveryActionByPoolKeyAndSkipsItemsThatAreNotActions(): void
    {
        $mail = $this->createStub(DeferrableActionInterface::class);
        $plain = $this->createStub(SubmissionActionInterface::class);

        $pool = new SubmissionActionPool(['plain' => $plain, 'junk' => new \stdClass(), 'mail' => $mail, 'text']);

        $this->assertSame(['plain' => $plain, 'mail' => $mail], $pool->all());
    }

    public function testItListsOnlyTheDeferrableActionsByPoolKey(): void
    {
        $mail = $this->createStub(DeferrableActionInterface::class);

        $pool = new SubmissionActionPool(['other' => $this->createStub(SubmissionActionInterface::class), 'mail' => $mail]);

        $this->assertSame(['mail' => $mail], $pool->deferrable());
    }

    public function testItStartsEmpty(): void
    {
        $pool = new SubmissionActionPool();

        $this->assertSame([], $pool->all());
        $this->assertSame([], $pool->deferrable());
    }
}
