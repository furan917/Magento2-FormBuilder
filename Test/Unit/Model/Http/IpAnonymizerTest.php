<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Http;

use Furan\FormBuilder\Model\Http\IpAnonymizer;
use PHPUnit\Framework\TestCase;

class IpAnonymizerTest extends TestCase
{
    public function testItTruncatesIpv4ToTheFirstThreeOctets(): void
    {
        $this->assertSame('203.0.113.0', (new IpAnonymizer())->anonymize('203.0.113.77'));
    }

    public function testItTruncatesIpv6ToTheFirstThreeHextets(): void
    {
        $this->assertSame('2001:db8:abcd::', (new IpAnonymizer())->anonymize('2001:db8:abcd:12:1:2:3:4'));
    }

    public function testItStoresNothingForAnAddressThatIsNotAnIp(): void
    {
        $this->assertNull((new IpAnonymizer())->anonymize(''));
        $this->assertNull((new IpAnonymizer())->anonymize('not-an-ip'));
    }
}
