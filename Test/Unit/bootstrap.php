<?php
declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;
use Magento\Framework\TestFramework\Unit\Autoloader\ProxyGenerator;

$magentoRoot = dirname(__DIR__, 6);
require $magentoRoot . '/vendor/autoload.php';
require $magentoRoot . '/dev/furan-tools/vendor/autoload.php';

// Factories and proxies don't exist outside a compiled install; generate them on demand like Magento's unit suite.
$generatedDir = $magentoRoot . '/var/furan-phpunit-generated';
spl_autoload_register([
    new GeneratedClassesAutoloader(
        [
            new ExtensionAttributesGenerator(),
            new ExtensionAttributesInterfaceGenerator(),
            new FactoryGenerator(),
            new ProxyGenerator(),
        ],
        new Io(new File(), $generatedDir)
    ),
    'load',
]);
