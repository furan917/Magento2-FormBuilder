<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\SettingLabels;
use Furan\FormBuilder\Model\Schema\FieldChecks;
use Furan\FormBuilder\Model\Validation\RulePool;

class FieldChecksFactory
{
    /** The upload allowlist of etc/config.xml. */
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'csv', 'docx', 'xlsx', 'odt', 'ods'];

    /**
     * @param string[] $extensions
     */
    public static function create(?RulePool $rules = null, array $extensions = self::EXTENSIONS): FieldChecks
    {
        $config = new class ($extensions) extends Config {
            /** @param string[] $extensions */
            public function __construct(private readonly array $extensions)
            {
            }

            public function getAllowedExtensions(?int $storeId = null): array
            {
                return $this->extensions;
            }
        };
        return new FieldChecks($rules ?? new RulePool(), $config, new SettingLabels(), new \Furan\FormBuilder\Model\Upload\ExtensionList());
    }
}
