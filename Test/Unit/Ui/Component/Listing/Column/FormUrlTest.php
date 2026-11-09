<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Ui\Component\Listing\Column;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Ui\Component\Listing\Column\FormUrl;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class FormUrlTest extends TestCase
{
    public function testItShowsTheStorefrontPathAsPlainText(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getRoutePrefix')->willReturn('forms');
        $column = new FormUrl(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $config,
            [],
            ['name' => 'url']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [['identifier' => 'contact']]]]);

        $this->assertSame('/forms/contact', $result['data']['items'][0]['url']);
    }
}
