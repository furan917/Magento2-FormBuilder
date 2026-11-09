<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\FieldType\Email;
use PHPUnit\Framework\TestCase;

class PoolTest extends TestCase
{
    public function testItReturnsFieldTypesFromThePoolInConfiguredOrder(): void
    {
        $text = new Text();
        $email = new Email();
        $pool = new Pool(['email' => $email, 'text' => $text]);

        $this->assertTrue($pool->has('text'));
        $this->assertFalse($pool->has('nope'));
        $this->assertSame($text, $pool->get('text'));
        $this->assertSame(['email', 'text'], array_keys($pool->all()));
    }

    public function testItThrowsForAnUnknownFieldTypeCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Pool([]))->get('nope');
    }

    public function testItTreatsAPayloadEntryOfAnUnknownTypeAsNotAFile(): void
    {
        $pool = FieldPools::withFiles();

        $this->assertFalse($pool->isFile('nope'));
        $this->assertFalse($pool->isFile(''));
        $this->assertFalse($pool->isFile('text'));
        $this->assertTrue($pool->isFile('doc'));
    }

    public function testItTreatsTheHiddenCodeAsAServerValueEvenWhenItsClassLacksTheInterfaceOrIsNotRegistered(): void
    {
        $this->assertTrue((new Pool([]))->isServerValue('hidden'));
        $this->assertTrue((new Pool(['hidden' => new Text()]))->isServerValue('hidden'));
        $this->assertFalse((new Pool(['text' => new Text()]))->isServerValue('text'));
    }

    public function testItAsksATypeWhetherItIsAnEmailAddress(): void
    {
        $pool = new Pool(['text' => new Text(), 'email' => new \Furan\FormBuilder\Model\FieldType\Email()]);

        $this->assertTrue($pool->isEmailAddress('email'));
        $this->assertFalse($pool->isEmailAddress('text'));
        $this->assertFalse($pool->isEmailAddress('gone'));
    }
}
