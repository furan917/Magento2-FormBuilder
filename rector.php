<?php
declare(strict_types=1);

use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__])
    ->withSkip([
        __DIR__ . '/Test/fixtures',
        __DIR__ . '/view',
        __DIR__ . '/i18n',
        __DIR__ . '/docs',
        // phpcs Magento2 warns on intval(...) and strval(...) but not on the string callable
        FunctionFirstClassCallableRector::class,
        // this test pins the class names written in di.xml and events.xml as plain strings
        StringClassNameToClassConstantRector::class => [__DIR__ . '/Test/Unit/Config/RecaptchaConfigTest.php'],
        // Magento types Collection::$_totalRecords as int, so ??= fails PHPStan; the null check is real at runtime
        \Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector::class => [__DIR__ . '/Model/ResourceModel/Submission/Grid/Collection.php'],
    ])
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_81)
    ->withSets([LevelSetList::UP_TO_PHP_81]);
