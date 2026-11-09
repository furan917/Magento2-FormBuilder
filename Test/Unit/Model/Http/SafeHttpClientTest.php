<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Http;

use Furan\FormBuilder\Api\Data\HttpResult;
use Furan\FormBuilder\Model\Http\PublicAddress;
use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Api\Data\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeHttpClientTest extends TestCase
{
    private FakeCurlSession $session;

    protected function setUp(): void
    {
        $this->session = new FakeCurlSession();
    }

    /** @param string[] $records */
    private function client(array $records = ['93.184.216.34']): SafeHttpClient
    {
        return new SafeHttpClient($this->session, new PublicAddress(static fn (string $host): array => $records));
    }

    private function url(string $host = 'hooks.example.com', int $port = 443, string $scheme = 'https'): SafeUrl
    {
        return new SafeUrl($scheme, $host, $port, '/in?x=1', false);
    }

    public function testItPinsTheConnectionToTheValidatedAddressAndReChecksItBeforeSending(): void
    {
        $this->session->status = 200;
        $this->client(['2606:4700::1111'])->request('POST', $this->url(), new RequestPolicy(), [], '{}');
        $this->assertSame(['::[2606:4700::1111]:443'], $this->session->options[CURLOPT_CONNECT_TO]);
        $this->assertSame('https://hooks.example.com/in?x=1', $this->session->options[CURLOPT_URL]);

        $this->session = new FakeCurlSession();
        $this->client()->request('GET', $this->url(port: 8443), new RequestPolicy(ports: [8443]));
        $this->assertSame(['::93.184.216.34:8443'], $this->session->options[CURLOPT_CONNECT_TO]);

        if (!defined('CURLOPT_PREREQFUNCTION')) {
            $this->markTestIncomplete('CURLOPT_PREREQFUNCTION needs PHP 8.4');
        }
        $this->assertSame(0, $this->session->prereq('93.184.216.34', 8443));
        $this->assertSame(1, $this->session->prereq('93.184.216.34', 443));
        $this->assertSame(1, $this->session->prereq('10.0.0.9', 8443));
        $this->assertSame(1, $this->session->prereq('not-an-ip', 8443));
    }

    public function testItAbortsWhenThePrereqCheckSeesAnotherAddress(): void
    {
        if (!defined('CURLOPT_PREREQFUNCTION')) {
            $this->markTestSkipped('CURLOPT_PREREQFUNCTION needs PHP 8.4');
        }
        $this->session->script = function (FakeCurlSession $s): bool {
            $s->prereq('10.0.0.9');
            $s->errno = 42;
            return false;
        };
        $result = $this->client()->request('GET', $this->url(), new RequestPolicy());
        $this->assertSame('blocked', $result->errorClass);
        $this->assertSame(42, $result->errno);
    }

    /** @return array<string,array{0:string[]}> */
    public static function mixedRecords(): array
    {
        return [
            'private only' => [['10.0.0.1']],
            'public then private' => [['93.184.216.34', '169.254.169.254']],
            'public then private v6' => [['93.184.216.34', 'fd00::1']],
        ];
    }

    /** @param string[] $records */
    #[DataProvider('mixedRecords')]
    public function testItRefusesWhenAnyResolvedAddressIsNotPublic(array $records): void
    {
        $client = $this->client($records);
        $this->assertSame('blocked', $client->preflight($this->url(), new RequestPolicy()));
        $result = $client->request('GET', $this->url(), new RequestPolicy());
        $this->assertSame('blocked', $result->errorClass);
        $this->assertSame(0, $result->status);
        $this->assertSame(0, $this->session->opened);
    }

    public function testItJudgesAnIpLiteralDirectly(): void
    {
        $client = $this->client([]);
        $literal = new SafeUrl('https', '127.0.0.1', 443, '/', true);
        $this->assertSame('blocked', $client->preflight($literal, new RequestPolicy()));
        $allowed = new RequestPolicy(ipLiteralHosts: ['127.0.0.1'], allowCidrs: ['127.0.0.0/8']);
        $this->assertSame('ok', $client->preflight($literal, $allowed));
    }

    public function testItReportsAnUnresolvedHostAsADnsFailureNotABlock(): void
    {
        $client = $this->client([]);
        $this->assertSame('unresolved', $client->preflight($this->url(), new RequestPolicy()));
        $result = $client->request('GET', $this->url(), new RequestPolicy());
        $this->assertSame('dns', $result->errorClass);
        $this->assertSame(0, $this->session->opened);
        $this->assertSame('ok', $this->client()->preflight($this->url(), new RequestPolicy()));
    }

    public function testItDisablesRedirectsProxiesAndProtocolsThePolicyDoesNotAllow(): void
    {
        $this->client()->request('GET', $this->url(), new RequestPolicy());
        $o = $this->session->options;
        $this->assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $o[CURLOPT_MAXREDIRS]);
        $this->assertSame('', $o[CURLOPT_PROXY]);
        $this->assertSame('*', $o[CURLOPT_NOPROXY]);
        $this->assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTPS, $o[CURLOPT_REDIR_PROTOCOLS]);
        $this->assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $o[CURLOPT_SSL_VERIFYHOST]);
        $this->assertTrue($o[CURLOPT_FORBID_REUSE]);
        $this->assertTrue($o[CURLOPT_FRESH_CONNECT]);
        $this->assertSame(3000, $o[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertSame(10000, $o[CURLOPT_TIMEOUT_MS]);
        $this->assertContains('Expect:', $o[CURLOPT_HTTPHEADER]);
        $this->assertArrayNotHasKey(CURLOPT_COOKIEFILE, $o);

        $this->session = new FakeCurlSession();
        $this->client()->request('GET', $this->url(scheme: 'http', port: 80), new RequestPolicy(allowHttp: true, ports: [80]));
        $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $this->session->options[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $this->session->options[CURLOPT_REDIR_PROTOCOLS]);
    }

    public function testItKeepsOnlyTheUsefulResponseHeadersAndCapsTheirTotal(): void
    {
        $this->session->status = 301;
        $this->session->script = function (FakeCurlSession $s): bool {
            $s->header("HTTP/1.1 301 Moved\r\n");
            $s->header("Set-Cookie: a=b\r\n");
            $s->header("Retry-After: 120\r\n");
            $s->header('Location: https://x.test/' . str_repeat('a', 400) . "\r\n");
            $s->header("Content-Type: text/plain\r\n");
            return true;
        };
        $result = $this->client()->request('POST', $this->url(), new RequestPolicy(), [], 'x');
        $this->assertSame(['retry-after', 'location', 'content-type'], array_keys($result->headers));
        $this->assertSame('120', $result->headers['retry-after']);
        $this->assertSame(255, strlen($result->headers['location']));
        $this->assertSame(301, $result->status);

        $this->session->script = function (FakeCurlSession $s): bool {
            $aborted = 0;
            for ($i = 0; $i < 20; $i++) {
                $line = 'X-Pad: ' . str_repeat('a', 1000) . "\r\n";
                if ($s->header($line) !== strlen($line)) {
                    $aborted = 1;
                    break;
                }
            }
            $s->errno = $aborted === 1 ? 23 : 0;
            return false;
        };
        $result = $this->client()->request('GET', $this->url(), new RequestPolicy());
        $this->assertSame('response_too_large', $result->errorClass);
    }

    public function testItStopsReadingOnceTheResponseCapIsReachedAndKeepsTheStatus(): void
    {
        $this->session->status = 200;
        $this->session->script = function (FakeCurlSession $s): bool {
            $this->assertSame(512, $s->write(str_repeat('a', 512)));
            $this->assertSame(0, $s->write(str_repeat('b', 1024)));
            $s->errno = 23;
            return false;
        };
        $result = $this->client()->request('POST', $this->url(), new RequestPolicy(maxResponseBytes: 1024), [], '{}');
        $this->assertSame(200, $result->status);
        $this->assertTrue($result->bodyTruncated);
        $this->assertSame(1024, strlen($result->body));
        $this->assertGreaterThan(0, $result->status);
        $this->assertNull($result->errorClass);
    }

    public function testItClassifiesNetworkFailuresWithoutThrowing(): void
    {
        foreach ([6 => 'dns', 7 => 'connect', 28 => 'timeout', 60 => 'tls', 35 => 'tls', 99 => 'connect'] as $errno => $class) {
            $this->session->errno = $errno;
            $this->session->script = static fn (): bool => false;
            $result = $this->client()->request('GET', $this->url(), new RequestPolicy());
            $this->assertSame($class, $result->errorClass, (string)$errno);
            $this->assertSame($errno, $result->errno);
            $this->assertSame(0, $result->status);
        }
        $this->session->script = static function (): bool {
            throw new \RuntimeException('boom');
        };
        $this->assertSame('connect', $this->client()->request('GET', $this->url(), new RequestPolicy())->errorClass);
    }

    /** @return array<string,array{0:int}> */
    public static function securityOptions(): array
    {
        $options = [
            'connect_to' => CURLOPT_CONNECT_TO, 'protocols' => CURLOPT_PROTOCOLS, 'redir' => CURLOPT_REDIR_PROTOCOLS,
            'proxy' => CURLOPT_PROXY, 'noproxy' => CURLOPT_NOPROXY, 'follow' => CURLOPT_FOLLOWLOCATION,
            'maxredirs' => CURLOPT_MAXREDIRS, 'verifypeer' => CURLOPT_SSL_VERIFYPEER,
            'verifyhost' => CURLOPT_SSL_VERIFYHOST, 'write' => CURLOPT_WRITEFUNCTION,
            'header' => CURLOPT_HEADERFUNCTION, 'timeout' => CURLOPT_TIMEOUT_MS,
        ];
        return array_map(static fn (int $option): array => [$option], $options);
    }

    #[DataProvider('securityOptions')]
    public function testItFailsClosedWhenASecurityOptionCannotBeSet(int $option): void
    {
        $this->session->rejected = [$option];
        $executed = false;
        $this->session->script = static function () use (&$executed): bool {
            $executed = true;
            return true;
        };
        $result = $this->client()->request('GET', $this->url(), new RequestPolicy());
        $this->assertSame('unsupported', $result->errorClass);
        $this->assertFalse($executed);
    }

    public function testItFailsClosedWhenNoHandleCanBeOpened(): void
    {
        $session = new class extends FakeCurlSession {
            public function open(): ?object
            {
                return null;
            }
        };
        $client = new SafeHttpClient($session, new PublicAddress(static fn (): array => ['93.184.216.34']));
        $this->assertSame('unsupported', $client->request('GET', $this->url(), new RequestPolicy())->errorClass);
    }

    /** @return array<string,array{0:array<string,string>}> */
    public static function badHeaders(): array
    {
        return [
            'crlf value' => [['X-A' => "ok\r\nHost: evil"]],
            'lf value' => [['X-A' => "a\nb"]],
            'nul value' => [['X-A' => "a\0b"]],
            'colon name' => [['X-A: b' => 'c']],
            'space name' => [['X A' => 'c']],
            'empty name' => [['' => 'c']],
            'long name' => [[str_repeat('a', 65) => 'c']],
            'host' => [['Host' => 'internal']],
            'content-length' => [['content-length' => '1']],
            'transfer-encoding' => [['Transfer-Encoding' => 'chunked']],
            'connection' => [['CONNECTION' => 'close']],
        ];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('badHeaders')]
    public function testItRejectsHeaderInjectionInCallerHeaders(array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client()->request('POST', $this->url(), new RequestPolicy(), $headers, '{}');
        } finally {
            $this->assertSame(0, $this->session->opened);
        }
    }

    public function testItSendsValidCallerHeadersAndRejectsUnsupportedMethods(): void
    {
        $this->client()->request('POST', $this->url(), new RequestPolicy(), ['Content-Type' => 'application/json', 'webhook-id' => 'msg_1'], '{"a":1}');
        $this->assertContains('Content-Type: application/json', $this->session->options[CURLOPT_HTTPHEADER]);
        $this->assertContains('webhook-id: msg_1', $this->session->options[CURLOPT_HTTPHEADER]);
        $this->assertSame('{"a":1}', $this->session->options[CURLOPT_POSTFIELDS]);
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->request('DELETE', $this->url(), new RequestPolicy());
    }

    public function testItReturnsTheConnectedIpAndStatus(): void
    {
        $this->session->status = 204;
        $this->session->primaryIp = '93.184.216.34';
        $result = $this->client()->request('GET', $this->url(), new RequestPolicy());
        $this->assertSame(204, $result->status);
        $this->assertSame('93.184.216.34', $result->connectedIp);
        $this->assertNull($result->errorClass);
        $this->assertInstanceOf(HttpResult::class, $result);
    }
}
