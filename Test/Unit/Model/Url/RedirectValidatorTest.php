<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Url;

use Furan\FormBuilder\Model\Url\RedirectValidator;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class RedirectValidatorTest extends TestCase
{
    private function validator(): RedirectValidator
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(
            fn ($type, $secure) => $secure ? 'https://shop.test/' : 'http://shop.test/'
        );
        $other = $this->createStub(Store::class);
        $other->method('getBaseUrl')->willReturnCallback(
            fn ($type, $secure) => $secure ? 'https://de.shop.test:8443/' : 'http://de.shop.test:8080/'
        );
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([$store, $other]);
        return new RedirectValidator($manager);
    }

    public function testItAllowsRelativeAndSameHostRedirects(): void
    {
        $v = $this->validator();
        foreach (['', '/thank-you', '/a/b?x=1#y', 'https://shop.test/ok', 'http://SHOP.test/ok',
                  'https://shop.test:443/ok', 'http://shop.test:80/ok', 'http://de.shop.test:8080/x',
                  'https://de.shop.test:8443/x'] as $url) {
            $this->assertTrue($v->isAllowed($url), $url);
        }
    }

    public function testItRejectsProtocolRelativeBackslashJavascriptAndForeignHostRedirects(): void
    {
        $v = $this->validator();
        foreach (['//evil.test', '/\\evil.test', '\\\\evil.test', '/a\\b', 'javascript:alert(1)',
                  'JaVaScRiPt:alert(1)', 'data:text/html,x', 'https://evil.test/', 'https://shop.test.evil.test/',
                  'https://user@evil.test/', 'https://shop.test@evil.test/', 'https://evil.test@shop.test/',
                  'ftp://shop.test/', "/ok\r\nLocation: x", "/a\tb", '/a b', ' /ok', "/ok\x00", 'thank-you',
                  'https:///x', '/%0d%0a', 'https://shop.test:99999/'] as $url) {
            $this->assertFalse($v->isAllowed($url), $url);
        }
    }

    public function testItRejectsAStoreHostOnAnotherPortOrScheme(): void
    {
        $v = $this->validator();
        foreach (['https://shop.test:9999/', 'http://shop.test:9999/', 'https://shop.test:80/', 'http://shop.test:443/',
                  'https://de.shop.test/x', 'http://de.shop.test/x', 'https://de.shop.test:8080/x'] as $url) {
            $this->assertFalse($v->isAllowed($url), $url);
        }
    }
}
