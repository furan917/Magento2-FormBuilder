<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Number;
use PHPUnit\Framework\TestCase;

class NumberTest extends TestCase
{
    public function testItSendsTheStepMessageToTheBrowserOnlyWhenTheFieldHasAStep(): void
    {
        $type = new Number();

        $this->assertSame(
            ['messages' => ['step' => 'Please enter a whole number.']],
            $type->getClientMessages(['settings' => ['step' => '1'], 'rules' => []])
        );
        $this->assertSame([], $type->getClientMessages(['settings' => ['step' => 'any'], 'rules' => []]));
        $this->assertSame([], $type->getClientMessages(['rules' => []]));
    }
}
