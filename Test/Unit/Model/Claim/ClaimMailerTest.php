<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Claim;

use Furan\FormBuilder\Model\Claim\ClaimMailer;
use Furan\FormBuilder\Model\Claim\ClaimToken;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ClaimMailerTest extends TestCase
{
    public function testItBraceEncodesTheCustomerNameUsedInsideATransDirective(): void
    {
        $vars = null;
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateOptions', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('setTemplateVars')->willReturnCallback(function (array $v) use (&$vars, $builder) {
            $vars = $v;
            return $builder;
        });
        $builder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));
        $ids = [];
        $builder->method('setTemplateIdentifier')->willReturnCallback(function (string $id) use (&$ids, $builder) {
            $ids[] = $id;
            return $builder;
        });
        $factory = $this->createStub(TransportBuilderFactory::class);
        $factory->method('create')->willReturn($builder);
        $config = $this->createMock(Config::class);
        $config->method('getEmailTemplate')->with('claim', 1)->willReturn('chosen_claim_1');
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(static fn (string $p) => $p === 'crypt/key' ? 'k' : null);
        $url = $this->createStub(UrlInterface::class);
        $store = $this->createStub(StoreInterface::class);
        $params = null;
        $url->method('getUrl')->willReturnCallback(function (string $route, array $p) use (&$params): string {
            $params = $p;
            return 'https://shop.test/furanform/account/claim/?token=1.2.abc';
        });
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->with(1)->willReturn($store);

        (new ClaimMailer(
            $config,
            new ClaimToken(new SigningKeys($deployment)),
            new EmailHasher($deployment),
            $factory,
            $this->createStub(StateInterface::class),
            $url,
            $stores
        ))->send(5, 'a@example.com', 'Eve {{var this.getTemplateFilter()}}', 1);

        $this->assertSame(['chosen_claim_1'], $ids);
        $this->assertSame('Eve &#123;&#123;var this.getTemplateFilter()&#125;&#125;', $vars['customer_name']);
        $this->assertSame('https://shop.test/furanform/account/claim/?token=1.2.abc', $vars['claim_url']);
        $this->assertSame($store, $params['_scope']);
        $this->assertTrue($params['_scope_to_url']);
    }
}
