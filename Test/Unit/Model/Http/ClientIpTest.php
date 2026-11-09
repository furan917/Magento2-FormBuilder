<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Http;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Http\ClientIp;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase
{
    private function ip(string $remote, ?string $forwarded = null, array $trusted = []): ClientIp
    {
        $request = $this->createStub(Http::class);
        $request->method('getServer')->willReturnCallback(
            static fn (string $name) => ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $forwarded][$name] ?? null
        );
        $config = $this->createStub(Config::class);
        $config->method('getTrustedProxies')->willReturn($trusted);

        return new ClientIp($request, $config);
    }

    public function testItIgnoresForwardedHeadersWhenNoProxyIsTrusted(): void
    {
        $this->assertSame('203.0.113.9', $this->ip('203.0.113.9', '1.1.1.1')->address());
    }

    public function testItIgnoresForwardedHeadersFromAPeerThatIsNotTrusted(): void
    {
        $this->assertSame('203.0.113.9', $this->ip('203.0.113.9', '1.1.1.1', ['10.0.0.0/8'])->address());
    }

    public function testItTakesTheRightMostUntrustedHopBehindATrustedProxy(): void
    {
        // The left-most entries are whatever the client sent, so they are never believed.
        $ip = $this->ip('10.0.0.5', '6.6.6.6, 198.51.100.7, 10.0.0.9', ['10.0.0.0/8']);

        $this->assertSame('198.51.100.7', $ip->address());
    }

    public function testItMatchesExactAddressesAndIpv6Ranges(): void
    {
        $this->assertSame('198.51.100.7', $this->ip('192.0.2.1', '198.51.100.7', ['192.0.2.1'])->address());
        $this->assertSame('2001:db8::1', $this->ip('fd00::1', '2001:db8::1', ['fd00::/8'])->address());
    }

    public function testItFallsBackToThePeerWhenEveryHopIsTrustedOrGarbage(): void
    {
        $this->assertSame('10.0.0.5', $this->ip('10.0.0.5', '10.0.0.6, not-an-ip', ['10.0.0.0/8'])->address());
        $this->assertSame('10.0.0.5', $this->ip('10.0.0.5', null, ['10.0.0.0/8'])->address());
    }

    public function testItBucketsAnAddressWithoutReadingTheRequest(): void
    {
        $ip = $this->ip('198.51.100.1');

        $this->assertSame('203.0.113.9', $ip->bucketOf('203.0.113.9'));
        $this->assertSame('2001:db8:0:1::/64', $ip->bucketOf('2001:db8:0:1:aaaa:bbbb:cccc:dddd'));
        $this->assertSame('unknown', $ip->bucketOf(''));
    }

    public function testItTreatsIpv4MappedAddressesAsIpv4(): void
    {
        $this->assertSame('203.0.113.9', $this->ip('::ffff:203.0.113.9')->bucket());
        $this->assertSame('203.0.113.9', $this->ip('::ffff:203.0.113.9')->address(), 'stored IPs anonymise as IPv4');
        $this->assertSame('198.51.100.7', $this->ip('::ffff:10.0.0.5', '198.51.100.7', ['10.0.0.0/8'])->address());
    }

    public function testItBucketsIpv6ByTheSlash64SoOneHostCannotRotateAddresses(): void
    {
        $this->assertSame('2001:db8:1:2::/64', $this->ip('2001:db8:1:2:aaaa::1')->bucket());
        $this->assertSame('2001:db8:1:2::/64', $this->ip('2001:db8:1:2:bbbb::9')->bucket());
        $this->assertSame('203.0.113.9', $this->ip('203.0.113.9')->bucket());
        $this->assertSame('unknown', $this->ip('')->bucket());
    }
}
