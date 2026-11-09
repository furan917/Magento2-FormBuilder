<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Magento\Framework\Model\AbstractModel;

/**
 * Real models built without their framework constructor, so tests need no mock for plain data holders.
 */
class ModelFixtures
{
    /**
     * @template T of AbstractModel
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     * @return T
     */
    public static function model(string $class, ?int $id = null, array $data = []): AbstractModel
    {
        $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $model->setIdFieldName('id');
        $model->setData($data);
        if ($id !== null) {
            $model->setId($id);
        }
        return $model;
    }
}
