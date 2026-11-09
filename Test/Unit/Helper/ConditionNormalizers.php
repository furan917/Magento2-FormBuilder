<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Condition\ConditionNormalizer;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Url\RedirectValidator;

class ConditionNormalizers
{
    /** Every built-in operator and field type of etc/di.xml, plus any extra operators and actions. */
    public static function builtIn(array $extraOperators = [], ?Pool $types = null, array $extraActions = []): ConditionNormalizer
    {
        $redirects = (new \ReflectionClass(RedirectValidator::class))->newInstanceWithoutConstructor();
        return new ConditionNormalizer(
            OperatorPools::builtIn($extraOperators),
            $types ?? FieldPools::builtIn(),
            new SettingValues($redirects),
            ActionPools::builtIn($extraActions)
        );
    }
}
