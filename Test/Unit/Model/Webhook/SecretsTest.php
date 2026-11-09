<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\Endpoint;
use Furan\FormBuilder\Model\Webhook\Secrets;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class SecretsTest extends TestCase
{
    private function secrets(): Secrets
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $v): string => preg_match('/^enc\[(.*)\]$/s', $v, $m) ? $m[1] : ''
        );

        return new Secrets($encryptor, static fn (): int => WebhookFixtures::NOW);
    }

    public function testItDecryptsTheCurrentSecretForTheSigner(): void
    {
        $endpoint = new Endpoint(7, 'enc[whsec_current]', null, null);

        $this->assertSame('whsec_current', $this->secrets()->current($endpoint));
    }

    public function testItOffersThePreviousSecretOnlyUntilItExpires(): void
    {
        $secrets = $this->secrets();
        $future = gmdate('Y-m-d H:i:s', WebhookFixtures::NOW + 60);
        $past = gmdate('Y-m-d H:i:s', WebhookFixtures::NOW - 1);
        $now = gmdate('Y-m-d H:i:s', WebhookFixtures::NOW);

        $this->assertSame('whsec_old', $secrets->previous(new Endpoint(7, 'enc[x]', 'enc[whsec_old]', $future)));
        $this->assertNull($secrets->previous(new Endpoint(7, 'enc[x]', 'enc[whsec_old]', $past)));
        $this->assertNull($secrets->previous(new Endpoint(7, 'enc[x]', 'enc[whsec_old]', $now)));
        $this->assertNull($secrets->previous(new Endpoint(7, 'enc[x]', 'enc[whsec_old]', null)));
        $this->assertNull($secrets->previous(new Endpoint(7, 'enc[x]', null, $future)));
        $this->assertNull($secrets->previous(new Endpoint(7, 'enc[x]', 'enc[whsec_old]', 'garbage')));
    }

    public function testItDoesNotExposeTheEncryptedSecretsWhenTheEndpointIsPrinted(): void
    {
        $endpoint = new Endpoint(7, 'enc[whsec_current]', 'enc[whsec_old]', null);

        $this->assertStringNotContainsString('whsec_', print_r($endpoint, true));
        $this->assertStringNotContainsString('enc[', print_r($endpoint, true));
    }
}
