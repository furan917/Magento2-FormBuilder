<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Http;

use Furan\FormBuilder\Model\Http\PublicAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicAddressTest extends TestCase
{
    public static function internalAddresses(): array
    {
        return array_map(static fn (string $ip): array => [$ip], [
            '0.0.0.0', '0.1.2.3', '10.0.0.1', '100.64.0.1', '100.127.255.254', '127.0.0.1', '169.254.169.254',
            '172.16.0.1', '172.31.255.255', '192.0.0.8', '192.0.2.1', '192.168.1.1', '198.18.0.1', '198.51.100.7',
            '203.0.113.9', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255',
            '::', '::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1', 'fc00::1', 'fd12:3456::1',
            'fe80::1', 'fec0::1', 'ff02::1', '2001:db8::1', '2002:a00:1::1', '100::1', 'not-an-ip',
        ]);
    }

    #[DataProvider('internalAddresses')]
    public function testItRejectsPrivateLoopbackLinkLocalCgnatMulticastAndReservedAddresses(string $ip): void
    {
        $this->assertFalse((new PublicAddress())->isPublic($ip), $ip);
    }

    public function testItAcceptsPublicAddresses(): void
    {
        $addresses = new PublicAddress();
        foreach (['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.1', '172.32.0.1', '2a00:1450:4009::1',
                  '2606:4700::1111', '::ffff:8.8.8.8'] as $ip) {
            $this->assertTrue($addresses->isPublic($ip), $ip);
        }
    }

    public function testItResolvesAHostOnlyWhenEveryAddressIsPublic(): void
    {
        $addresses = new PublicAddress(fn (string $host): array => [
            'geo.test' => ['93.184.216.34', '2606:2800:220:1::1'],
            'mixed.test' => ['93.184.216.34', '10.0.0.5'],
            'none.test' => [],
        ][$host] ?? []);

        $this->assertSame('93.184.216.34', $addresses->resolve('geo.test'));
        $this->assertNull($addresses->resolve('mixed.test'));
        $this->assertNull($addresses->resolve('none.test'));
        $this->assertSame('8.8.8.8', $addresses->resolve('8.8.8.8'));
        $this->assertNull($addresses->resolve('127.0.0.1'));
        $this->assertNull($addresses->resolve('[::1]'));
        $this->assertSame('2606:4700::1111', $addresses->resolve('[2606:4700::1111]'));
    }

    public static function sharedTable(): array
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/ip-classification.json'), true);
        $cases = [];
        foreach ($rows as $row) {
            $cases[$row['address'] . (empty($row['nat64']) ? '' : ' (nat64)')] = [
                $row['address'], $row['expected'], !empty($row['nat64']),
            ];
        }
        return $cases;
    }

    #[DataProvider('sharedTable')]
    public function testItClassifiesEveryAddressInTheSharedTableCorrectly(string $ip, bool $expected, bool $nat64): void
    {
        $this->assertSame($expected, (new PublicAddress())->isPublic($ip, $nat64), $ip);
    }

    public static function outsideGlobalUnicast(): array
    {
        return array_map(static fn (string $ip): array => [$ip], [
            '1::1', '3fff::1', '4000::1', '5f00::1', '8000::1', 'a000::1', 'e000::1', 'fe00::1', 'ffff::1',
        ]);
    }

    #[DataProvider('outsideGlobalUnicast')]
    public function testItRejectsIpv6OutsideTheGlobalUnicastRange(string $ip): void
    {
        $this->assertFalse((new PublicAddress())->isPublic($ip, true), $ip);
    }

    public function testItJudgesIpv4MappedIpv6AsItsIpv4AddressAndBlocksCompatibleAndTranslatedForms(): void
    {
        $addresses = new PublicAddress();
        $this->assertTrue($addresses->isPublic('::ffff:8.8.8.8'));
        $this->assertFalse($addresses->isPublic('::ffff:10.0.0.1'));
        foreach (['::8.8.8.8', '::808:808', '::ffff:0:808:808', '::ffff:0:8.8.8.8'] as $ip) {
            $this->assertFalse($addresses->isPublic($ip, true, ['0.0.0.0/0']), $ip);
        }
        $this->assertSame('8.8.8.8', (new PublicAddress(fn (): array => ['::ffff:8.8.8.8']))->lookup('m.test')['ip']);
    }

    public function testItBlocksNat64AddressesUnlessDecodingIsEnabled(): void
    {
        $addresses = new PublicAddress(fn (): array => ['64:ff9b::808:808']);
        $this->assertFalse($addresses->isPublic('64:ff9b::808:808'));
        $this->assertSame(['status' => 'blocked', 'ip' => null], $addresses->lookup('n.test'));
    }

    public function testItDecodesNat64AndJudgesTheEmbeddedIpv4WhenEnabled(): void
    {
        $addresses = new PublicAddress(fn (string $host): array => [
            'ok.test' => ['64:ff9b::808:808'], 'bad.test' => ['64:ff9b::7f00:1'],
        ][$host] ?? []);
        $this->assertTrue($addresses->isPublic('64:ff9b::808:808', true));
        $this->assertFalse($addresses->isPublic('64:ff9b::7f00:1', true));
        $this->assertSame(['status' => 'ok', 'ip' => '64:ff9b::808:808'], $addresses->lookup('ok.test', true));
        $this->assertSame('blocked', $addresses->lookup('bad.test', true)['status']);
    }

    public function testItTellsAnUnresolvedHostApartFromABlockedOne(): void
    {
        $addresses = new PublicAddress(fn(string $host): array => match ($host) {
            'ok.test' => ['93.184.216.34', '2606:2800:220:1::1'],
            'mixed.test' => ['93.184.216.34', '10.0.0.5'],
            'junk.test' => ['93.184.216.34', 'nonsense'],
            'boom.test' => throw new \ErrorException('dns warning'),
            default => [],
        });
        $this->assertSame(['status' => 'ok', 'ip' => '93.184.216.34'], $addresses->lookup('ok.test'));
        $this->assertSame(['status' => 'blocked', 'ip' => null], $addresses->lookup('mixed.test'));
        $this->assertSame(['status' => 'blocked', 'ip' => null], $addresses->lookup('junk.test'));
        $this->assertSame(['status' => 'unresolved', 'ip' => null], $addresses->lookup('none.test'));
        $this->assertSame(['status' => 'unresolved', 'ip' => null], $addresses->lookup('boom.test'));
        $this->assertSame(['status' => 'blocked', 'ip' => null], $addresses->lookup('127.0.0.1'));
        $this->assertSame(['status' => 'ok', 'ip' => '8.8.8.8'], $addresses->lookup('8.8.8.8'));
        $this->assertSame(['status' => 'blocked', 'ip' => null], $addresses->lookup('[::1]'));
    }

    public function testItAcceptsAddressesInsideAllowedCidrsOnlyForThoseRanges(): void
    {
        $addresses = new PublicAddress();
        $allow = ['172.18.0.0/16', 'bad', '10.0.0.0/99', 'fd00::/8'];
        $this->assertTrue($addresses->isPublic('172.18.0.5', false, $allow));
        $this->assertTrue($addresses->isPublic('fd00::5', false, $allow));
        $this->assertFalse($addresses->isPublic('172.19.0.5', false, $allow));
        $this->assertFalse($addresses->isPublic('10.0.0.1', false, $allow));
        $this->assertFalse($addresses->isPublic('127.0.0.1', false, $allow));
        $this->assertFalse($addresses->isPublic('not-an-ip', false, ['0.0.0.0/0']));
        $resolver = new PublicAddress(fn (): array => ['172.18.0.5', '10.0.0.5']);
        $this->assertSame('blocked', $resolver->lookup('x.test', false, $allow)['status']);
    }

    /** @return array{0: PublicAddress, 1: \stdClass, 2: \stdClass} */
    private function cachedAddresses(array $records): array
    {
        $calls = new \stdClass();
        $calls->count = [];
        $clock = new \stdClass();
        $clock->now = 1000;
        $addresses = new PublicAddress(
            function (string $host) use ($records, $calls): array {
                $calls->count[$host] = ($calls->count[$host] ?? 0) + 1;
                return $records[$host] ?? [];
            },
            static fn (): int => $clock->now
        );

        return [$addresses, $calls, $clock];
    }

    public function testItResolvesAHostOncePerCacheWindow(): void
    {
        [$addresses, $calls, $clock] = $this->cachedAddresses(['geo.test' => ['93.184.216.34']]);

        $addresses->lookup('geo.test');
        $clock->now += PublicAddress::CACHE_TTL - 1;
        $addresses->lookup('GEO.test');

        $this->assertSame(1, $calls->count['geo.test']);
    }

    public function testItReResolvesAfterTheCacheWindow(): void
    {
        [$addresses, $calls, $clock] = $this->cachedAddresses(['geo.test' => ['93.184.216.34']]);

        $addresses->lookup('geo.test');
        $clock->now += PublicAddress::CACHE_TTL;
        $addresses->lookup('geo.test');

        $this->assertSame(2, $calls->count['geo.test']);
    }

    public function testItClassifiesACachedHostAgainWithTheCallersAllowList(): void
    {
        [$addresses] = $this->cachedAddresses(['dev.test' => ['10.0.0.5']]);

        $this->assertSame('blocked', $addresses->lookup('dev.test')['status']);
        $this->assertSame('10.0.0.5', $addresses->lookup('dev.test', false, ['10.0.0.0/8'])['ip']);
        $this->assertSame('blocked', $addresses->lookup('dev.test')['status']);
    }

    public function testItCachesAnUnresolvedHostForTenSecondsAndAResolvedOneForSixty(): void
    {
        [$addresses, $calls, $clock] = $this->cachedAddresses(['geo.test' => ['93.184.216.34']]);

        $this->assertSame('unresolved', $addresses->lookup('dead.test')['status']);
        $clock->now += PublicAddress::NEGATIVE_CACHE_TTL - 1;
        $this->assertSame('unresolved', $addresses->lookup('dead.test')['status']);
        $this->assertSame(1, $calls->count['dead.test']);
        $clock->now += 1;
        $addresses->lookup('dead.test');
        $this->assertSame(2, $calls->count['dead.test']);

        $addresses->lookup('geo.test');
        $clock->now += PublicAddress::NEGATIVE_CACHE_TTL;
        $addresses->lookup('geo.test');
        $this->assertSame(1, $calls->count['geo.test']);
        $this->assertSame(10, PublicAddress::NEGATIVE_CACHE_TTL);
        $this->assertSame(60, PublicAddress::CACHE_TTL);
    }

    public function testItKeepsABlockedHostBlockedWhileItIsCached(): void
    {
        [$addresses, $calls, $clock] = $this->cachedAddresses(['int.test' => ['10.0.0.5']]);

        $this->assertSame('blocked', $addresses->lookup('int.test')['status']);
        $clock->now += PublicAddress::NEGATIVE_CACHE_TTL;
        $this->assertSame('blocked', $addresses->lookup('int.test')['status']);
        $this->assertNull($addresses->resolve('int.test'));
        $this->assertSame(1, $calls->count['int.test']);
    }

    public function testItNeverLetsACachedResultTurnABlockedAddressIntoAnAllowedOne(): void
    {
        [$addresses, $calls] = $this->cachedAddresses(['dev.test' => ['10.0.0.5']]);

        $addresses->lookup('dev.test', false, ['10.0.0.0/8']);

        $this->assertNull($addresses->lookup('dev.test')['ip']);
        $this->assertNull($addresses->resolve('dev.test'));
        $this->assertSame(1, $calls->count['dev.test']);
    }

    public function testItKeepsAtMostTheCappedNumberOfHosts(): void
    {
        [$addresses, $calls] = $this->cachedAddresses([]);

        for ($i = 0; $i <= PublicAddress::CACHE_MAX_HOSTS; $i++) {
            $addresses->lookup('h' . $i . '.test');
        }
        $addresses->lookup('h1.test');
        $addresses->lookup('h0.test');

        $this->assertSame(1, $calls->count['h1.test']);
        $this->assertSame(2, $calls->count['h0.test']);
    }
}
