<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Model\Condition\Today;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Tel;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Submission\Validator;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ActionPools;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ValidatorMaskTest extends TestCase
{
    private function validator(): Validator
    {
        return new Validator(
            new Pool(['text' => new Text(), 'tel' => new Tel()]),
            new RulePool(),
            new Evaluator(OperatorPools::builtIn(), FieldPools::builtIn(), ActionPools::builtIn()),
            $this->createStub(LoggerInterface::class),
            new Today($this->createStub(ScopeConfigInterface::class))
        );
    }

    private function schema(string $type, string $mask): array
    {
        return ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'condition' => null, 'items' => [[
            'id' => 'f_aaaa01', 'kind' => 'field', 'type' => $type, 'name' => 'code', 'required' => false,
            'rules' => [], 'options' => [], 'settings' => ['mask' => $mask], 'condition' => null,
        ]]]]];
    }

    public function testItStoresTheMaskedValueConsistentlyOnTheServer(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/mask-fields.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as ['type' => $type, 'mask' => $mask, 'posted' => $posted, 'stored' => $stored]) {
            $result = $this->validator()->validate($this->schema($type, $mask), ['code' => $posted], []);
            $this->assertSame($stored, $result->getValues()['code'], $mask . ' ' . $posted);
        }
    }

    public function testItLeavesAFieldWithoutAMaskUntouched(): void
    {
        $schema = $this->schema('text', '');
        $result = $this->validator()->validate($schema, ['code' => '123456'], []);
        $this->assertSame('123456', $result->getValues()['code']);
    }
}
