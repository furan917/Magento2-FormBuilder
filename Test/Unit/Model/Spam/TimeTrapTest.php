<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Model\Spam\TimeTrap;
use Furan\FormBuilder\Model\Spam\TokenService;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use Magento\Framework\App\DeploymentConfig;
use Furan\FormBuilder\Api\SubmissionInput;
use PHPUnit\Framework\TestCase;

class TimeTrapTest extends TestCase
{
    private const STORE_ID = 4;

    private int $now = 1000000;

    private function request(mixed $token): SubmissionInput
    {
        return new SubmissionInput(formToken: is_string($token) ? $token : '', storeId: self::STORE_ID);
    }

    private function form(): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(5);
        return $form;
    }

    private function trap(TokenService $tokens, ?Config $config = null): TimeTrap
    {
        if ($config === null) {
            $config = $this->createStub(Config::class);
            $config->method('getMinSubmitSeconds')->willReturn(3);
        }
        return new TimeTrap($tokens, $config);
    }

    private function realTokens(): TokenService
    {
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(
            static fn (string $path) => $path === 'crypt/key' ? 'trapkey' : '2024-01-01'
        );
        return new TokenService(
            new SigningKeys($deployment),
            new MemoryCounter(fn (): int => $this->now),
            fn (): int => $this->now
        );
    }

    private function stubbed(?int $age, bool $verifies): TokenService
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->method('age')->with('tok', 5)->willReturn($age);
        $tokens->method('verify')->with('tok', 5, 3)->willReturn($verifies);
        return $tokens;
    }

    private function code(SpamResult $result): ?string
    {
        return $result->isPass() ? null : $result->getCode();
    }

    public function testItRejectsAMissingOrInvalidTimeTrapToken(): void
    {
        $this->assertSame('token', $this->code($this->trap($this->stubbed(null, false))->check($this->request(null), $this->form())));
        $this->assertSame('token', $this->code($this->trap($this->stubbed(null, false))->check($this->request(''), $this->form())));
        $this->assertSame('token', $this->code($this->trap($this->stubbed(null, false))->check($this->request(['tok']), $this->form())));
        $this->assertSame('token', $this->code($this->trap($this->stubbed(null, false))->check($this->request('tok'), $this->form())));
        $this->assertSame('token', $this->code($this->trap($this->stubbed(10, false))->check($this->request('tok'), $this->form())));
        $this->assertNull($this->code($this->trap($this->stubbed(10, true))->check($this->request('tok'), $this->form())));
    }

    public function testItRejectsATooEarlySubmitWithAWaitMessageAndStatus425(): void
    {
        $tokens = $this->realTokens();
        $token = $tokens->issue(5);
        $this->now += 1;

        $result = $this->trap($tokens)->check($this->request($token), $this->form());

        $this->assertTrue($result->isReject());
        $this->assertSame('too_early', $result->getCode());
        $this->assertSame(425, $result->getHttpCode());
        $this->assertSame('Please wait a moment and try again.', (string)$result->getMessage());
    }

    public function testItStillRejectsATooEarlySubmitMadeWithoutTheBrowserScript(): void
    {
        $tokens = $this->realTokens();
        $token = $tokens->issue(5);

        $result = $this->trap($tokens)->check($this->request($token), $this->form());
        $this->assertSame('too_early', $this->code($result));
        $this->now += 3;
        $this->assertNull($this->code($this->trap($tokens)->check($this->request($token), $this->form())));
    }

    public function testItRejectsAForgedOrForeignTokenWithTheRefreshMessage(): void
    {
        $tokens = $this->realTokens();
        $token = $tokens->issue(9);
        $this->now += 1;
        $forged = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

        foreach ([$token, $forged, '1.2.3'] as $bad) {
            $result = $this->trap($tokens)->check($this->request($bad), $this->form());
            $this->assertSame('token', $result->getCode());
            $this->assertSame(400, $result->getHttpCode());
            $this->assertNull($result->getMessage());
        }
    }

    public function testItReadsTheSameMinimumSecondsForTheTokenResponseAndTheTimeTrap(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->atLeastOnce())->method('getMinSubmitSeconds')->with(self::STORE_ID)->willReturn(3);

        $this->trap($this->stubbed(10, true), $config)->check($this->request('tok'), $this->form());
    }
}
