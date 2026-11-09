<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Thread\ThreadHeaders;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Message;

class ThreadHeadersTest extends TestCase
{
    private string $baseUrl = 'https://Shop.Example.com:8443/en/';

    private function headers(string $keys = 'first second'): ThreadHeaders
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn (string $path) => $path === 'crypt/key' ? $keys : null);
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(fn () => $this->baseUrl);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new ThreadHeaders($config, $stores);
    }

    public function testItBuildsAStableRootIdPerSubmissionOnTheStoreHost(): void
    {
        $id = $this->headers()->rootId(42, 1);

        $this->assertMatchesRegularExpression('/^ffb-42-[0-9a-f]{16}@shop\.example\.com$/D', $id);
        $this->assertSame($id, $this->headers()->rootId(42, 1));
        $this->assertNotSame($id, $this->headers()->rootId(43, 1));
    }

    public function testItKeepsTheRootIdWhenANewCryptKeyIsAdded(): void
    {
        $this->assertSame($this->headers('first')->rootId(42, 1), $this->headers('first second')->rootId(42, 1));
    }

    public function testItFallsBackToLocalhostForAnUnusableHost(): void
    {
        $this->baseUrl = 'not a url';
        $this->assertStringEndsWith('@localhost', $this->headers()->rootId(1, null));
    }

    public function testItAddsInReplyToAndReferencesToTheOutgoingMessage(): void
    {
        $symfony = new Message();
        $symfony->getHeaders()->addIdHeader('In-Reply-To', 'old@x.test');
        $transport = $this->transportFor($symfony);

        $this->headers()->apply($transport, 'ffb-42-0123456789abcdef@shop.example.com');

        $raw = $symfony->getHeaders()->toString();
        $this->assertStringContainsString("In-Reply-To: <ffb-42-0123456789abcdef@shop.example.com>\r\n", $raw);
        $this->assertStringContainsString("References: <ffb-42-0123456789abcdef@shop.example.com>\r\n", $raw);
        $this->assertStringNotContainsString('old@x.test', $raw);
        $this->assertFalse($symfony->getHeaders()->has('Message-ID'));
    }

    public function testItLeavesMessagesWithoutAHeaderApiUntouched(): void
    {
        $message = $this->createStub(EmailMessageInterface::class);
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);

        $this->headers()->apply($transport, 'ffb-1-0123456789abcdef@localhost');
        $this->addToAssertionCount(1);
    }

    private function transportFor(Message $symfony): TransportInterface
    {
        $message = new class ($symfony) {
            public function __construct(private readonly Message $message)
            {
            }

            public function getSymfonyMessage(): Message
            {
                return $this->message;
            }
        };
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);
        return $transport;
    }
}
