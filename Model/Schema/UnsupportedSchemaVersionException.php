<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Api\Exception\InvalidSchemaException;

class UnsupportedSchemaVersionException extends InvalidSchemaException
{
    public function __construct(private readonly int $version, string $message)
    {
        parent::__construct([$message]);
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
