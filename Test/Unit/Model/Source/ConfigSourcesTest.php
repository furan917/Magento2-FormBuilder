<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Source;

use Furan\FormBuilder\Model\Source\CustomerDeleteBehaviour;
use Furan\FormBuilder\Model\Source\MapProvider;
use PHPUnit\Framework\TestCase;

class ConfigSourcesTest extends TestCase
{
    public function testItOffersOpenStreetMapAndGoogleAsMapProviderOptions(): void
    {
        $options = (new MapProvider())->toOptionArray();
        $this->assertSame(['osm', 'google'], array_column($options, 'value'));
        $this->assertSame(['OpenStreetMap', 'Google Maps'], array_map('strval', array_column($options, 'label')));
    }

    public function testItOffersAnonymiseKeepAndDeleteAsAccountDeletionOptions(): void
    {
        $options = (new CustomerDeleteBehaviour())->toOptionArray();
        $this->assertSame(['anonymise', 'keep', 'delete'], array_column($options, 'value'));
    }
}
