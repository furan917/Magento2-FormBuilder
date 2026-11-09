<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Email;

use Furan\FormBuilder\Model\Email\SafeAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeAddressTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function addresses(): array
    {
        return [
            'plain' => ['ann@example.com', true],
            'hyphen inside' => ['ann-lee@example.com', true],
            'underscore first' => ['_info@example.com', true],
            'plus tag' => ['ann+forms@example.com', true],
            'leading hyphen' => ['-oQ/tmp/x@example.com', false],
            'leading hyphen short' => ['-a@example.com', false],
            'line break' => ["ann@example.com\r\nBcc: x@y.z", false],
            'trailing newline' => ["ann@example.com\n", false],
            'no domain dot' => ['ann@example', false],
            'not a string' => [['ann@example.com'], false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testItOnlyAcceptsAddressesThatAreSafeToHandToTheMailTransport(mixed $address, bool $expected): void
    {
        $this->assertSame($expected, SafeAddress::isValid($address));
    }
}
