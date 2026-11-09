<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Model\Condition\Today;
use Furan\FormBuilder\Model\Email\EmailConditions;
use Magento\Framework\App\Config\ScopeConfigInterface;

class EmailConditionsFactory
{
    public static function create(string $timezone = 'UTC'): EmailConditions
    {
        $config = new class ($timezone) implements ScopeConfigInterface {
            public function __construct(private readonly string $timezone)
            {
            }

            public function getValue($path, $scopeType = 'default', $scopeCode = null)
            {
                return $this->timezone;
            }

            public function isSetFlag($path, $scopeType = 'default', $scopeCode = null)
            {
                return false;
            }
        };

        return new EmailConditions(
            new Evaluator(OperatorPools::builtIn(), FieldPools::builtIn(), ActionPools::builtIn()),
            new Today($config)
        );
    }
}
