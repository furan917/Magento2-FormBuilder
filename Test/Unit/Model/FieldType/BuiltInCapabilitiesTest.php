<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;
use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Api\FieldType\StorefrontConfigInterface;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\TestCase;

class BuiltInCapabilitiesTest extends TestCase
{
    public function testItKeepsTodaysBehaviourForEveryBuiltInType(): void
    {
        $personal = $nonSources = $unroutable = $clientMessages = $storefront = [];
        foreach (FieldPools::builtIn()->all() as $code => $type) {
            if ($type instanceof PersonalByDefaultInterface && $type->isPersonalByDefault()) {
                $personal[] = $code;
            }
            if ($type instanceof ConditionSourceInterface && !$type->canBeConditionSource()) {
                $nonSources[] = $code;
            }
            if ($type->isFile() || ($type instanceof RoutableTypeInterface && !$type->canBeRouted())) {
                $unroutable[] = $code;
            }
            if ($type instanceof ClientMessagesInterface) {
                $clientMessages[] = $code;
            }
            if ($type instanceof StorefrontConfigInterface) {
                $storefront[] = $code;
            }
        }

        $this->assertEqualsCanonicalizing(['email', 'tel', 'address', 'map', 'file'], $personal);
        $this->assertEqualsCanonicalizing(['matrix', 'date_range', 'address', 'map'], $nonSources);
        $this->assertEqualsCanonicalizing(['date_range', 'address', 'map', 'file'], $unroutable);
        $this->assertEqualsCanonicalizing(['matrix', 'date_range', 'address', 'map', 'number'], $clientMessages);
        $this->assertEqualsCanonicalizing(['address', 'map'], $storefront);
    }
}
