<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Plugin;

use Furan\FormBuilder\Plugin\GuestAccountResult;
use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\TestCase;

class GuestAccountResultTest extends TestCase
{
    public function testItReturnsTheResponseWhenTheCustomerPluginSkippedTheActionForAGuest(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $plugin = new GuestAccountResult($response);

        $this->assertSame($response, $plugin->afterExecute($this->createStub(AccountInterface::class), null));
    }

    public function testItPassesTheActionResultThroughForALoggedInCustomer(): void
    {
        $result = $this->createStub(ResultInterface::class);
        $plugin = new GuestAccountResult($this->createStub(ResponseInterface::class));

        $this->assertSame($result, $plugin->afterExecute($this->createStub(AccountInterface::class), $result));
    }

    public function testItWrapsEveryAccountControllerOutsideTheCustomerAccountPlugin(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/frontend/di.xml');
        $wrapped = [];
        foreach ($xml->xpath('//type[plugin[@name="furan_formbuilder_guest_account_result"]]') as $type) {
            $plugin = $type->xpath('plugin[@name="furan_formbuilder_guest_account_result"]')[0];
            $this->assertLessThan(0, (int)$plugin['sortOrder']);
            $wrapped[] = (string)$type['name'];
        }
        $expected = [];
        foreach (glob(dirname(__DIR__, 3) . '/Controller/Account/*.php') as $file) {
            $class = 'Furan\\FormBuilder\\Controller\\Account\\' . basename($file, '.php');
            if (is_subclass_of($class, AccountInterface::class)) {
                $expected[] = $class;
            }
        }
        sort($wrapped);
        sort($expected);
        $this->assertSame($expected, $wrapped);
    }
}
