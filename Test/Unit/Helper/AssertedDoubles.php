<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use PHPUnit\Framework\MockObject\Stub;

/**
 * Builds a collaborator as a mock only when the test says it asserts on it, and as a stub otherwise.
 */
trait AssertedDoubles
{
    /** @var list<string> */
    private array $asserted = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T&Stub
     */
    private function double(string $name, string $class): Stub
    {
        return in_array($name, $this->asserted, true) ? $this->createMock($class) : $this->createStub($class);
    }
}
