<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\SecretStash;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Session\Storage;
use PHPUnit\Framework\TestCase;

class SecretStashTest extends TestCase
{
    private Storage $storage;
    private SecretStash $stash;

    protected function setUp(): void
    {
        $this->storage = new Storage();
        $session = (new \ReflectionClass(Session::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(\Magento\Framework\Session\SessionManager::class, 'storage');
        $property->setValue($session, $this->storage);
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $v): string => 'enc[' . strrev($v) . ']');
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $v): string => preg_match('/^enc\[(.*)\]$/s', $v, $m) ? strrev($m[1]) : ''
        );
        $this->stash = new SecretStash($session, $encryptor);
    }

    public function testItShowsTheSecretOnceAndNeverAgain(): void
    {
        $this->stash->put(7, 'whsec_plainvalue');

        $this->assertStringNotContainsString('whsec_plainvalue', json_encode($this->storage->getData(), JSON_THROW_ON_ERROR));
        $this->assertSame('whsec_plainvalue', $this->stash->take(7));
        $this->assertNull($this->stash->take(7));
        $this->assertSame([], array_filter($this->storage->getData()));
    }

    public function testItKeepsTheSecretWhenAnotherEndpointIsOpened(): void
    {
        $this->stash->put(7, 'whsec_plainvalue');

        $this->assertNull($this->stash->take(8));
        $this->assertSame('whsec_plainvalue', $this->stash->take(7));
    }

    public function testItIgnoresAValueItCannotDecrypt(): void
    {
        $this->storage->setData('ffb_webhook_secret_7', 'garbage');

        $this->assertNull($this->stash->take(7));
    }

    public function testItPeeksAtTheSecretWithoutRemovingIt(): void
    {
        $this->stash->put(7, 'whsec_plainvalue');

        $this->assertSame('whsec_plainvalue', $this->stash->peek(7));
        $this->assertSame('whsec_plainvalue', $this->stash->peek(7));
        $this->assertSame('whsec_plainvalue', $this->stash->take(7));
    }
}
