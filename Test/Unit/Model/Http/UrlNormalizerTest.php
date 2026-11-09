<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Http;

use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Http\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    private int $seed = 20261108;

    private const REASONS = [
        'empty', 'too_long', 'malformed', 'control_character', 'backslash', 'fragment', 'scheme', 'userinfo',
        'percent_in_authority', 'host_idn', 'host_invalid', 'ip_literal_not_allowed', 'port', 'host_not_allowed',
        'path_invalid',
    ];

    private static function policy(array $overrides): RequestPolicy
    {
        return new RequestPolicy(...$overrides);
    }

    /** @return array<string, array{string, array, string}> */
    public static function sharedTable(): array
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/url-policy.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $i => $row) {
            $cases[$i . ' ' . json_encode($row['url'], JSON_UNESCAPED_UNICODE)] = [$row['url'], $row['policy'] ?? [], $row['expect']];
        }
        $label63 = str_repeat('a', 63);
        $label64 = str_repeat('a', 64);
        $host253 = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 57), 'com']);
        $cases['63 char label'] = ["https://$label63.com/", [], "https://$label63.com/"];
        $cases['64 char label'] = ["https://$label64.com/", [], 'host_invalid'];
        $cases['253 char host'] = ["https://$host253/", [], "https://$host253/"];
        $cases['254 char host'] = ["https://a$host253/", [], 'host_invalid'];
        $cases['too long url'] = ['https://example.com/' . str_repeat('a', 2100), [], 'too_long'];
        return $cases;
    }

    #[DataProvider('sharedTable')]
    public function testItRejectsEveryUnsafeUrlInTheSharedTable(string $url, array $policy, string $expect): void
    {
        $normalizer = new UrlNormalizer();
        if (!in_array($expect, self::REASONS, true)) {
            $this->assertSame($expect, $normalizer->normalize($url, self::policy($policy))->toString());
            return;
        }
        try {
            $normalizer->normalize($url, self::policy($policy));
            $this->fail('accepted');
        } catch (UnsafeUrlException $e) {
            $this->assertSame($expect, $e->getReason());
            $this->assertNotSame('', (string)$e->getMessage());
        }
    }

    public function testItNormalisesAnInternationalisedHostToAsciiAndCanonicalForm(): void
    {
        $safe = (new UrlNormalizer())->normalize('https://Bücher.Example./Straße?q=ü', new RequestPolicy());
        $this->assertSame('xn--bcher-kva.example', $safe->host);
        $this->assertSame('https', $safe->scheme);
        $this->assertSame(443, $safe->port);
        $this->assertSame('/Stra%C3%9Fe?q=%C3%BC', $safe->pathAndQuery);
        $this->assertFalse($safe->isIpLiteral);
        $this->assertSame('https://xn--bcher-kva.example/Stra%C3%9Fe?q=%C3%BC', $safe->toString());
        $again = (new UrlNormalizer())->normalize($safe->toString(), new RequestPolicy());
        $this->assertSame($safe->toString(), $again->toString());
    }

    /** @return array<string, array{string}> */
    public static function numericHosts(): array
    {
        $cases = [];
        foreach ([
            '2130706433', '0x7f.1', '0177.0.0.1', '127.1', '0x7f000001', '017700000001', '127.0.1', '0300.0250.0.1',
            '１２７.０.０.１', '127。0。0。1', '1.2.3.4.5', '256.1.1.1', '010.0.0.1', '1e3.1.1.1',
        ] as $host) {
            $cases[$host] = [$host];
        }
        return $cases;
    }

    #[DataProvider('numericHosts')]
    public function testItRejectsNumericHostsInEveryNotation(string $host): void
    {
        // Listing the literal must not rescue a non-canonical notation.
        $policy = new RequestPolicy(ipLiteralHosts: ['127.0.0.1', '8.8.8.8', '1.2.3.4']);
        try {
            (new UrlNormalizer())->normalize('https://' . $host . '/', $policy);
            $this->fail('accepted ' . $host);
        } catch (UnsafeUrlException $e) {
            $this->assertContains($e->getReason(), ['host_invalid', 'ip_literal_not_allowed']);
        }
    }

    public function testItRejectsIpLiteralsUnlessThePolicyListsThem(): void
    {
        $normalizer = new UrlNormalizer();
        foreach (['https://8.8.8.8/', 'https://[2606:4700::1]/'] as $url) {
            try {
                $normalizer->normalize($url, new RequestPolicy());
                $this->fail('accepted ' . $url);
            } catch (UnsafeUrlException $e) {
                $this->assertSame('ip_literal_not_allowed', $e->getReason());
            }
        }
        $policy = new RequestPolicy(ipLiteralHosts: ['8.8.8.8', '2606:4700::1']);
        $v4 = $normalizer->normalize('https://8.8.8.8/', $policy);
        $v6 = $normalizer->normalize('https://[2606:4700::1]/', $policy);
        $this->assertTrue($v4->isIpLiteral);
        $this->assertTrue($v6->isIpLiteral);
        $this->assertSame('2606:4700::1', $v6->host);
        $this->assertSame('https://[2606:4700::1]/', $v6->toString());
    }

    public function testItAppliesTheHostAllowlistToRealSubdomainsOnly(): void
    {
        $normalizer = new UrlNormalizer();
        $policy = new RequestPolicy(hostAllowlist: ['*.Example.com', 'hooks.other.org']);
        $this->assertSame('a.b.example.com', $normalizer->normalize('https://a.b.example.com/', $policy)->host);
        $this->assertSame('hooks.other.org', $normalizer->normalize('https://hooks.other.org/', $policy)->host);
        foreach (['example.com', 'evilexample.com', 'example.com.evil.com', 'other.org', 'x.hooks.other.org', 'a.example.com.evil.io'] as $host) {
            try {
                $normalizer->normalize('https://' . $host . '/', $policy);
                $this->fail('accepted ' . $host);
            } catch (UnsafeUrlException $e) {
                $this->assertSame('host_not_allowed', $e->getReason(), $host);
            }
        }
    }

    public function testItRebuildsEveryAcceptedUrlSoParsersAgreeOnItsHost(): void
    {
        $policy = new RequestPolicy(
            allowHttp: true,
            ports: [443, 80, 8443],
            ipLiteralHosts: ['127.0.0.1', '8.8.8.8', '::1', '2606:4700::1']
        );
        $normalizer = new UrlNormalizer();
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/url-policy.json'), true, 512, JSON_THROW_ON_ERROR);
        $seeds = array_column($rows, 'url');
        foreach ($rows as $row) {
            if (str_contains($row['expect'], '://')) {
                array_push($seeds, $row['url'], $row['url'], $row['url']);
            }
        }
        $seeds = array_merge($seeds, [
            'https://evil.com:443\\@127.0.0.1/', 'https://127.0.0.1#@evil.com/', 'https://evil.com%2f@127.0.0.1/',
            'https://127.0.0.1:80\\@evil.com/', 'https://a.com\\.evil.com/', 'https://a.com?@127.0.0.1/',
            'https://a.com/?@127.0.0.1/', 'https://[::1]:443/', 'http://8.8.8.8:80/', 'https://[::ffff:127.0.0.1]/',
            'https://a.com:443@127.0.0.1:80/', 'https:/\\/\\127.0.0.1/', 'https://127.0.0.1\\.example.com/',
        ]);
        $alphabet = ['@', '\\', '#', '%', '?', ':', '/', '.', '[', ']', ' ', "\t", "\0", 'é', '１', '。', '0', '1', 'a', '-', '_', '2', '5', 'x', '~', '&', '='];
        $accepted = 0;
        for ($i = 0; $i < 2000; $i++) {
            $url = $seeds[$this->rand(0, count($seeds) - 1)];
            foreach (range(0, $this->rand(0, 2)) as $_) {
                $url = $this->mutate($url, $alphabet);
            }
            try {
                $safe = $normalizer->normalize($url, $policy);
            } catch (UnsafeUrlException) {
                continue;
            }
            $accepted++;
            $canonical = $safe->toString();
            $parts = parse_url($canonical);
            $this->assertIsArray($parts, $canonical);
            $expectedHost = $safe->isIpLiteral && str_contains($safe->host, ':') ? '[' . $safe->host . ']' : $safe->host;
            $this->assertSame($expectedHost, $parts['host'] ?? null, json_encode($url) . ' -> ' . $canonical);
            $this->assertSame($safe->port, $parts['port'] ?? ($safe->scheme === 'https' ? 443 : 80), $canonical);
            $this->assertSame($safe->scheme, $parts['scheme'] ?? null, $canonical);
            $this->assertArrayNotHasKey('user', $parts, $canonical);
            $this->assertArrayNotHasKey('pass', $parts, $canonical);
            $this->assertArrayNotHasKey('fragment', $parts, $canonical);
            $this->assertSame($canonical, $normalizer->normalize($canonical, $policy)->toString(), 'idempotent: ' . $canonical);
        }
        $this->assertGreaterThan(100, $accepted, 'the mutation corpus must keep exercising accepted URLs');
    }

    /** @param string[] $alphabet */
    private function mutate(string $url, array $alphabet): string
    {
        $chars = preg_split('//u', $url, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pos = $chars === [] ? 0 : $this->rand(0, count($chars) - 1);
        $insert = $alphabet[$this->rand(0, count($alphabet) - 1)];
        match ($this->rand(0, 2)) {
            0 => array_splice($chars, $pos, 0, [$insert]),
            1 => array_splice($chars, $pos, 1, [$insert]),
            default => array_splice($chars, $pos, 1),
        };
        return implode('', $chars);
    }

    /** Seeded LCG so the corpus is identical on every run and PHP version. */
    private function rand(int $min, int $max): int
    {
        $this->seed = ($this->seed * 1103515245 + 12345) & 0x7fffffff;
        return $min + intdiv($this->seed >> 8, 1) % ($max - $min + 1);
    }
}
