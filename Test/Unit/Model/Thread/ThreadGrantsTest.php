<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Thread\ThreadGrants;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ThreadGrantsTest extends TestCase
{
    private const FP = 'fingerprint-bytes';

    private array $data = [];
    private int $now = 1_800_000_000;
    private Session&Stub $session;

    protected function setUp(): void
    {
        $this->session = $this->configureSession($this->createStub(Session::class));
    }

    /**
     * @template T of Session&Stub
     * @param T $session
     * @return T
     */
    private function configureSession(Session&Stub $session): Session&Stub
    {
        $session->method('__call')->willReturnCallback(function (string $method, array $args) use ($session) {
            if ($method === 'setData') {
                $this->data[$args[0]] = $args[1];
            }
            return $session;
        });
        $session->method('getData')->willReturnCallback(fn (string $key) => $this->data[$key] ?? null);

        return $session;
    }

    private function grants(): ThreadGrants
    {
        return new ThreadGrants($this->session, fn (): int => $this->now);
    }

    public function testItAllowsTheGrantedSubmissionForTheSameVersionAndRecipient(): void
    {
        $this->grants()->grant(42, 3, self::FP);

        $this->assertTrue($this->grants()->allows(42, 3, ['older', self::FP]));
        $this->assertFalse($this->grants()->allows(43, 3, [self::FP]));
    }

    public function testItRegeneratesTheSessionIdWhenGranting(): void
    {
        $session = $this->session = $this->configureSession($this->createMock(Session::class));
        $session->expects($this->once())->method('regenerateId');
        $this->grants()->grant(42, 0, self::FP);
    }

    public function testItRefusesOnceTheRevocationCounterMoves(): void
    {
        $this->grants()->grant(42, 3, self::FP);
        $this->assertFalse($this->grants()->allows(42, 4, [self::FP]));
    }

    public function testItRefusesWhenTheRecipientChangedOrWasAnonymised(): void
    {
        $this->grants()->grant(42, 0, self::FP);
        $this->assertFalse($this->grants()->allows(42, 0, ['someone-else']));
        $this->assertFalse($this->grants()->allows(42, 0, []));
    }

    public function testItExpiresAfterSixtyMinutes(): void
    {
        $this->grants()->grant(42, 0, self::FP);
        $this->now += 3600;
        $this->assertTrue($this->grants()->allows(42, 0, [self::FP]));
        $this->now += 1;
        $this->assertFalse($this->grants()->allows(42, 0, [self::FP]));
    }

    public function testItKeepsOnlyTheTenNewestGrants(): void
    {
        for ($id = 1; $id <= 12; $id++) {
            $this->grants()->grant($id, 0, self::FP);
        }
        $this->assertFalse($this->grants()->allows(1, 0, [self::FP]));
        $this->assertFalse($this->grants()->allows(2, 0, [self::FP]));
        $this->assertTrue($this->grants()->allows(3, 0, [self::FP]));
        $this->assertTrue($this->grants()->allows(12, 0, [self::FP]));
    }

    public function testItIgnoresMalformedSessionData(): void
    {
        $this->data['furan_formbuilder_thread_grants'] = 'junk';
        $this->assertFalse($this->grants()->allows(42, 0, [self::FP]));
        $this->data['furan_formbuilder_thread_grants'] = [42 => 'junk'];
        $this->assertFalse($this->grants()->allows(42, 0, [self::FP]));
    }
}
