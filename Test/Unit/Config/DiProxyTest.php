<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiProxyTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../etc/';

    /** @return array<string, array{string, class-string, string, class-string}> */
    public static function proxies(): array
    {
        return [
            'claim offer mail queue' => ['frontend/di.xml', \Furan\FormBuilder\Observer\OfferClaim::class, 'mailQueue', \Furan\FormBuilder\Model\Mail\MailQueue::class],
            'claim offer mailer' => ['frontend/di.xml', \Furan\FormBuilder\Observer\OfferClaim::class, 'claimMailer', \Furan\FormBuilder\Model\Claim\ClaimMailer::class],
            'router forms' => ['frontend/di.xml', \Furan\FormBuilder\Controller\Router::class, 'formRepository', \Furan\FormBuilder\Api\FormRepositoryInterface::class],
            'router cms pages' => ['frontend/di.xml', \Furan\FormBuilder\Controller\Router::class, 'pageResource', \Magento\Cms\Model\ResourceModel\Page::class],
            'newsletter customers' => ['di.xml', \Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class, 'customers', \Magento\Customer\Api\CustomerRepositoryInterface::class],
        ];
    }

    /**
     * @param class-string $type
     * @param class-string $dependency
     */
    #[DataProvider('proxies')]
    public function testItInjectsAProxyForADependencyNotNeededOnEveryCall(string $file, string $type, string $argument, string $dependency): void
    {
        $params = array_column(
            array_map(
                static fn (\ReflectionParameter $p): array => [$p->getName(), (string)$p->getType()],
                (new \ReflectionClass($type))->getConstructor()?->getParameters() ?? []
            ),
            1,
            0
        );
        $this->assertSame($dependency, $params[$argument] ?? null);

        $xml = simplexml_load_file(self::ETC . $file);
        $this->assertNotFalse($xml);
        $nodes = $xml->xpath(sprintf('/config/type[@name="%s"]/arguments/argument[@name="%s"]', $type, $argument)) ?: [];

        $this->assertCount(1, $nodes);
        $this->assertSame('object', (string)$nodes[0]->attributes('xsi', true)['type']);
        $this->assertSame($dependency . '\\Proxy', trim((string)$nodes[0]));
    }
}
