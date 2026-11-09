<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\SavedRule as SavedRuleResource;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\CollectionFactory as RuleCollectionFactory;
use Furan\FormBuilder\Model\SavedRule;
use Furan\FormBuilder\Model\SavedRuleFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\CacheContextFactory;

/**
 * Admin-side lifecycle of saved rules: validation, usage tracking and cache purging of forms that use them.
 */
class SavedRuleManager
{
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{1,62}$/D';

    public function __construct(
        private readonly SavedRuleFactory $ruleFactory,
        private readonly SavedRuleResource $resource,
        private readonly RuleCollectionFactory $ruleCollectionFactory,
        private readonly SavedRuleUsage $ruleUsage,
        private readonly PatternMatcher $matcher,
        private readonly RuleLibrary $library,
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function get(int $id): SavedRule
    {
        $rule = $this->ruleFactory->create();
        $this->resource->load($rule, $id);
        if (!$rule->getId()) {
            throw new NoSuchEntityException(__('This rule no longer exists.'));
        }
        return $rule;
    }

    /**
     * @param array<string, mixed> $data label, code, pattern, message, example
     * @throws LocalizedException listing every problem, one per line
     */
    public function save(array $data, ?int $id = null): SavedRule
    {
        $rule = $id ? $this->get($id) : $this->ruleFactory->create();
        $label = $this->clean($data['label'] ?? '', 128);
        $pattern = trim((string)($data['pattern'] ?? ''));
        $message = $this->clean($data['message'] ?? '', 255);
        $example = $this->clean($data['example'] ?? '', 128);
        // The code is what forms store, so it is fixed once the rule exists.
        $code = $rule->getId() ? (string)$rule->getData('code') : strtolower(trim((string)($data['code'] ?? '')));

        $errors = [];
        if ($label === '') {
            $errors[] = __('Enter a name.');
        }
        if (!$rule->getId()) {
            if (!preg_match(self::CODE_PATTERN, $code)) {
                $errors[] = __('The code must start with a letter and use only lowercase letters, numbers and underscores (2 to 63 characters).');
            } elseif ($this->codeTaken($code)) {
                $errors[] = __('A rule with the code "%1" already exists.', $code);
            }
        }
        if ($pattern === '' || !$this->matcher->isSafe($pattern)) {
            $errors[] = __('The pattern is invalid, too long or too complex. Keep it under 256 characters and avoid nested repeats such as (a+)+.');
        } elseif ($example !== '' && !$this->matcher->matches($pattern, $example)) {
            $errors[] = __('The example "%1" does not match the pattern.', $example);
        }
        if ($errors) {
            throw new LocalizedException(__(implode("\n", array_map('strval', $errors))));
        }

        $rule->addData([
            'code' => $code,
            'label' => $label,
            'pattern' => $pattern,
            'message' => $message !== '' ? $message : (string)__('Please match the requested format.'),
            'example' => $example,
        ]);
        $this->resource->save($rule);
        $this->library->reset();
        $this->purge($this->usage($code));
        return $rule;
    }

    /**
     * @throws LocalizedException when forms still use the rule
     */
    public function delete(int $id): void
    {
        $rule = $this->get($id);
        $forms = $this->usage((string)$rule->getData('code'));
        if ($forms) {
            throw new LocalizedException(__(
                'This rule is used by %1. Remove it from those fields first.',
                implode(', ', array_map(static fn (array $f) => '"' . $f['title'] . '"', $forms))
            ));
        }
        $this->resource->delete($rule);
        $this->library->reset();
    }

    /**
     * Forms with a field that uses the saved rule.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function usage(string $code): array
    {
        return $this->ruleUsage->forms($code);
    }

    /**
     * @param string[] $codes
     * @return array<string, int> saved rule code => number of forms using it
     */
    public function usageCounts(array $codes): array
    {
        return $this->ruleUsage->counts($codes);
    }

    private function codeTaken(string $code): bool
    {
        return $this->ruleCollectionFactory->create()->addFieldToFilter('code', $code)->getSize() > 0;
    }

    /**
     * @param array<int, array{id: int, title: string}> $forms
     */
    private function purge(array $forms): void
    {
        if (!$forms) {
            return;
        }
        $context = $this->cacheContextFactory->create();
        $context->registerEntities(Form::CACHE_TAG, array_column($forms, 'id'));
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
    }

    private function clean(mixed $value, int $max): string
    {
        $value = is_scalar($value) ? preg_replace('/[\x00-\x1F\x7F]/', ' ', (string)$value) ?? '' : '';
        return mb_substr(trim($value), 0, $max);
    }
}
