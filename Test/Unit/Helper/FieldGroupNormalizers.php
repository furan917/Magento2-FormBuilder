<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\FieldGroup\GroupNormalizer;
use Furan\FormBuilder\Model\FieldType\Checkboxes;
use Furan\FormBuilder\Model\FieldType\Date;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\RulePool;
use Magento\Store\Model\StoreManagerInterface;

class FieldGroupNormalizers
{
    /** A group normalizer over the real schema Normalizer and Translator with a few built-in field types. */
    public static function create(StoreManagerInterface $stores, array $extraActions = []): GroupNormalizer
    {
        $types = new Pool([
            'text' => new Text(), 'select' => new Select(), 'checkboxes' => new Checkboxes(), 'date' => new Date(),
            'hidden' => new Hidden(),
        ]);
        $redirects = new RedirectValidator($stores);
        $normalizer = new Normalizer(
            $types,
            new RulePool(),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues($redirects),
            new PersonalData(FieldPools::builtIn()),
            new Sources(),
            ConditionNormalizers::builtIn([], $types, $extraActions)
        );
        return new GroupNormalizer($normalizer, TranslatorFactory::create($redirects, $types));
    }
}
