<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Ui\Component\Listing\Column;

use Furan\FormBuilder\Ui\Component\Listing\Column\Reference;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class ReferenceTest extends TestCase
{
    private function column(): Reference
    {
        return new Reference(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            [],
            ['name' => 'reference']
        );
    }

    public function testItTurnsTheStoredBytesIntoTheHyphenatedReferenceSoNoBinaryReachesJson(): void
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [
            ['submission_id' => 4, 'reference' => hex2bin('0194f3a0111172228333444455556666')],
            ['submission_id' => 5, 'reference' => 'broken'],
        ]]]);

        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $result['data']['items'][0]['reference']);
        $this->assertSame('', $result['data']['items'][1]['reference']);
        $this->assertNotFalse(json_encode($result));
    }
}
