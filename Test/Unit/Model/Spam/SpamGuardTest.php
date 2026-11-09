<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SpamCheckInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Api\ValuesSpamCheckInterface;
use Furan\FormBuilder\Model\Spam\SpamGuard;
use Furan\FormBuilder\Api\SubmissionInput;
use PHPUnit\Framework\TestCase;

class SpamGuardTest extends TestCase
{
    private function check(?string $reason, bool $expectCalled = true): SpamCheckInterface
    {
        $check = $this->createMock(SpamCheckInterface::class);
        $check->expects($expectCalled ? $this->once() : $this->never())->method('check')
            ->willReturn($reason === null ? SpamResult::pass() : SpamResult::reject($reason));
        return $check;
    }

    private function valuesCheck(SpamResult $result, bool $expectCalled = true): ValuesSpamCheckInterface
    {
        $check = $this->createMock(ValuesSpamCheckInterface::class);
        $check->expects($expectCalled ? $this->once() : $this->never())->method('checkValues')->willReturn($result);
        return $check;
    }

    public function testItReturnsTheFirstFailingCheckResultFromTheGuard(): void
    {
        $guard = new SpamGuard([
            'a' => $this->check(null),
            'junk' => new \stdClass(),
            'b' => $this->check('token'),
            'c' => $this->check('rate_limit', false),
        ]);
        $result = $guard->check(new SubmissionInput(), $this->createStub(FormInterface::class));
        $this->assertTrue($result->isReject());
        $this->assertSame('token', $result->getCode());
        $empty = new SpamGuard([]);
        $this->assertTrue(
            $empty->check(new SubmissionInput(), $this->createStub(FormInterface::class))->isPass()
        );
    }

    public function testItRunsOnlyValuesChecksInTheValuesStage(): void
    {
        $silent = SpamResult::silent('bot');
        $guard = new SpamGuard([
            'request_only' => $this->check(null, false),
            'ok' => $this->valuesCheck(SpamResult::pass()),
            'drop' => $this->valuesCheck($silent),
            'after' => $this->valuesCheck(SpamResult::reject('x'), false),
        ]);

        $result = $guard->checkValues(
            $this->createStub(FormInterface::class),
            ['name' => 'Ann'],
            new SubmissionInput()
        );

        $this->assertSame($silent, $result);
    }

    public function testItSkipsValuesChecksInTheRequestStage(): void
    {
        $guard = new SpamGuard(['v' => $this->valuesCheck(SpamResult::reject('x'), false)]);

        $this->assertTrue(
            $guard->check(new SubmissionInput(), $this->createStub(FormInterface::class))->isPass()
        );
    }

    public function testItPassesTheSubmissionInputToEverySpamCheck(): void
    {
        $input = new SubmissionInput(honeypot: 'x', clientIp: '203.0.113.9');
        $form = $this->createStub(FormInterface::class);
        $request = $this->createMock(SpamCheckInterface::class);
        $request->expects($this->once())->method('check')->with($input, $form)->willReturn(SpamResult::pass());
        $values = $this->createMock(ValuesSpamCheckInterface::class);
        $values->expects($this->once())->method('checkValues')->with($form, ['a' => '1'], $input)
            ->willReturn(SpamResult::pass());
        $guard = new SpamGuard(['request' => $request, 'values' => $values]);

        $this->assertTrue($guard->check($input, $form)->isPass());
        $this->assertTrue($guard->checkValues($form, ['a' => '1'], $input)->isPass());
    }
}
