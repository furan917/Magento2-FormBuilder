<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Api\Data;

use Furan\FormBuilder\Api\Data\DisplayContext;
use PHPUnit\Framework\TestCase;

class DisplayContextTest extends TestCase
{
    public function testItCarriesTheStoreViewAndItsLocale(): void
    {
        $context = DisplayContext::forStore(3, 'fr_FR');

        $this->assertSame(3, $context->storeId);
        $this->assertSame('fr_FR', $context->locale);
    }

    public function testItGivesTheAdminViewTheAdminStoreAndTheAdminsLocale(): void
    {
        $context = DisplayContext::forAdmin('de_DE');

        $this->assertSame(0, $context->storeId);
        $this->assertSame('de_DE', $context->locale);
    }

    public function testItCannotBeChangedOnceBuilt(): void
    {
        $this->expectException(\Error::class);
        $context = DisplayContext::forStore(1, 'en_GB');
        $context->locale = 'fr_FR'; // @phpstan-ignore property.readOnlyAssignOutOfClass
    }
}
