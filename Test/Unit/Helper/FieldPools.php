<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Directory\AddressRules;
use Furan\FormBuilder\Model\FieldType\Address;
use Furan\FormBuilder\Model\FieldType\DateRange;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Map;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;

/**
 * Pools for tests that need file and hidden handling, including a custom file type whose code is not "file".
 */
class FieldPools
{
    public const CUSTOM_FILE = 'doc';

    public static function withFiles(): Pool
    {
        return self::structured([
            'file' => new File(),
            'hidden' => new Hidden(),
            self::CUSTOM_FILE => new class extends File {
                public function getCode(): string
                {
                    return FieldPools::CUSTOM_FILE;
                }
            },
        ]);
    }

    /** Every built-in type of etc/di.xml, built without constructors: for capability and metadata checks only. */
    public static function builtIn(): Pool
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        $types = [];
        foreach ($xml->xpath("//type[@name='" . Pool::class . "']/arguments/argument[@name='types']/item") as $item) {
            $types[(string)$item['name']] = (new \ReflectionClass(trim((string)$item)))->newInstanceWithoutConstructor();
        }
        return new Pool($types);
    }

    /** Built-in types (no constructors) plus the file and structured test types: for admin view layout checks. */
    public static function adminView(array $extra = []): Pool
    {
        return new Pool($extra + self::withFiles()->all() + self::builtIn()->all());
    }

    /** Text, the structured built-ins and the custom `point` type, plus any extra types. */
    public static function structured(array $extra = []): Pool
    {
        $rules = (new \ReflectionClass(AddressRules::class))->newInstanceWithoutConstructor();
        return new Pool($extra + [
            'text' => new Text(),
            'address' => new Address($rules, StorefrontUrlsFactory::create()),
            'date_range' => new DateRange(),
            'map' => (new \ReflectionClass(Map::class))->newInstanceWithoutConstructor(),
            'point' => new PointType(),
        ]);
    }
}
