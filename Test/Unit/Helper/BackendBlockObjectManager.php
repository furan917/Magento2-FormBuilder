<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/** Backend\Block\Template reads its helpers from the global object manager, so tests install one. */
trait BackendBlockObjectManager
{
    private ?ObjectManagerInterface $previousObjectManager = null;

    #[Before]
    protected function installBackendBlockObjectManager(): void
    {
        $instance = new \ReflectionProperty(ObjectManager::class, '_instance');
        $previous = $instance->getValue();
        $this->previousObjectManager = $previous instanceof ObjectManagerInterface ? $previous : null;
        ObjectManager::setInstance(new class implements ObjectManagerInterface {
            public function create($type, array $arguments = [])
            {
                return (new \ReflectionClass($type))->newInstanceWithoutConstructor();
            }

            public function get($type)
            {
                return $this->create($type);
            }

            public function configure(array $configuration)
            {
            }
        });
    }

    #[After]
    protected function restoreBackendBlockObjectManager(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, $this->previousObjectManager);
    }
}
