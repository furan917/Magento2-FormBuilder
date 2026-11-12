<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\StoreDisplayContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class StoreDisplayContextTest extends TestCase
{
    public function testItReadsTheStoreViewLocaleOncePerStore(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->expects($this->exactly(2))->method('getValue')->willReturnMap([
            ['general/locale/code', 'store', 2, 'fr_FR'],
            ['general/locale/code', 'store', 3, 'de_DE'],
        ]);
        $contexts = new StoreDisplayContext($config);

        $this->assertSame('fr_FR', $contexts->forStore(2)->locale);
        $this->assertSame('de_DE', $contexts->forStore(3)->locale);
        $this->assertSame(2, $contexts->forStore(2)->storeId);
        $this->assertSame($contexts->forStore(2), $contexts->forStore(2));
    }

    public function testItUsesTheDefaultScopeForADeletedStoreView(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->expects($this->once())->method('getValue')->with('general/locale/code', 'default', null)->willReturn('nl_NL');

        $this->assertSame('nl_NL', (new StoreDisplayContext($config))->forStore(null)->locale);
    }

    public function testItFallsBackToEnglishWithoutAConfiguredLocale(): void
    {
        $this->assertSame('en_US', (new StoreDisplayContext($this->createStub(ScopeConfigInterface::class)))->forStore(1)->locale);
    }
}
