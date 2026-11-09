<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Http;

use Furan\FormBuilder\Model\Http\CurlSession;

/**
 * Records options and lets a test script the transfer, including the callbacks curl would invoke.
 */
class FakeCurlSession extends CurlSession
{
    /** @var array<int,mixed> */
    public array $options = [];
    /** @var int[] options whose setOption call returns false */
    public array $rejected = [];
    public int $opened = 0;
    public int $errno = 0;
    public mixed $primaryIp = '';
    public int $status = 0;
    /** @var callable|null fn(FakeCurlSession): bool */
    public $script;
    private ?object $handle = null;

    public function open(): ?object
    {
        $this->opened++;
        $this->handle = new \stdClass();
        return $this->handle;
    }

    public function setOption(object $handle, int $option, mixed $value): bool
    {
        if (in_array($option, $this->rejected, true)) {
            return false;
        }
        $this->options[$option] = $value;
        return true;
    }

    public function execute(object $handle): bool
    {
        return $this->script === null ? true : ($this->script)($this);
    }

    public function info(object $handle, int $option): mixed
    {
        return match ($option) {
            CURLINFO_RESPONSE_CODE => $this->status,
            CURLINFO_PRIMARY_IP => $this->primaryIp,
            default => null,
        };
    }

    public function errorNumber(object $handle): int
    {
        return $this->errno;
    }

    public function header(string $line): int
    {
        return ($this->options[CURLOPT_HEADERFUNCTION])($this->handle, $line);
    }

    public function write(string $chunk): int
    {
        return ($this->options[CURLOPT_WRITEFUNCTION])($this->handle, $chunk);
    }

    /** Argument order as libcurl passes it: primary IP, local IP, primary port, local port. */
    public function prereq(string $primaryIp, int $primaryPort = 443): int
    {
        return ($this->options[CURLOPT_PREREQFUNCTION])($this->handle, $primaryIp, '10.0.0.5', $primaryPort, 40000);
    }
}
