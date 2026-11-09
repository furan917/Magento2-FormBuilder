<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Spam\Honeypot;
use Furan\FormBuilder\Api\SubmissionInput;
use PHPUnit\Framework\TestCase;

class HoneypotTest extends TestCase
{
    private function check(string $value): ?string
    {
        $result = (new Honeypot())->check(new SubmissionInput(honeypot: $value), $this->createStub(FormInterface::class));
        return $result->isPass() ? null : $result->getCode();
    }

    public function testItFlagsAFilledHoneypot(): void
    {
        $this->assertSame('honeypot', $this->check('http://spam.example'));
        $this->assertNull($this->check(''));
    }
}
