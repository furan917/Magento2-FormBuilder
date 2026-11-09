<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AvailabilityInterface;
use Furan\FormBuilder\Model\FieldType\Newsletter;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Newsletter\NewsletterBridge;
use PHPUnit\Framework\TestCase;

class NewsletterTest extends TestCase
{
    public function testItStoresTheNewsletterAnswerLikeACheckbox(): void
    {
        $type = $this->type(true);
        $field = ['type' => 'newsletter', 'settings' => []];

        $this->assertSame('choice', $type->getCategory());
        $this->assertSame('yes', $type->normalizeValue('yes', $field));
        $this->assertNull($type->validateValue('yes', $field, []));
        $this->assertNotNull($type->validateValue('no', $field, []));
        $this->assertSame(\Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface::KIND_CHOICE, $type->statsKind($field));
        $this->assertSame('', $type->getSettingsSpec()['email_field']['default']);
    }

    public function testItReportsAvailabilityFromTheBridgeForTheGivenStore(): void
    {
        $bridge = $this->createStub(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturnCallback(static fn (?int $store): bool => $store !== 2);
        $type = new Newsletter($bridge);

        $this->assertInstanceOf(AvailabilityInterface::class, $type);
        $this->assertTrue($type->isAvailable());
        $this->assertTrue($type->isAvailable(1));
        $this->assertFalse($type->isAvailable(2));
    }

    public function testItOnlyUsesPooledTypesThatAreAvailableForTheStore(): void
    {
        $pool = new Pool(['newsletter' => $this->type(false), 'text' => new \Furan\FormBuilder\Model\FieldType\Text()]);

        $this->assertTrue($pool->has('newsletter'));
        $this->assertFalse($pool->isUsable('newsletter'));
        $this->assertFalse($pool->isUsable('newsletter', 1));
        $this->assertTrue($pool->isUsable('text', 1));
        $this->assertFalse($pool->isUsable('nope'));
    }

    private function type(bool $available): Newsletter
    {
        $bridge = $this->createStub(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn($available);

        return new Newsletter($bridge);
    }
}
