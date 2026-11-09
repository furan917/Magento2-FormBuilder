<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

use Furan\FormBuilder\Model\ResourceModel\SavedRule\CollectionFactory;

/**
 * @phpstan-type RuleEntry array{ref: string, label: string, group: string, pattern: string, message: string, example: string, saved: bool}
 *
 * Resolves the value of a `preset` rule: a built-in code such as `uk_postcode`, or `saved:<code>` for a rule
 * an admin saved. Fields keep the reference, so editing a saved rule changes every form that uses it.
 */
class RuleLibrary
{
    public const SAVED_PREFIX = 'saved:';

    /** @var array<string, RuleEntry>|null */
    private ?array $saved = null;

    public function __construct(
        private readonly BuiltInRules $builtIn,
        private readonly CollectionFactory $collectionFactory,
        private readonly PatternMatcher $matcher
    ) {
    }

    /**
     * @return RuleEntry|null
     */
    public function find(string $ref): ?array
    {
        $entry = str_starts_with($ref, self::SAVED_PREFIX)
            ? ($this->savedRules()[$ref] ?? null)
            : (isset($this->builtIn->all()[$ref]) ? $this->builtInEntry($ref, $this->builtIn->all()[$ref]) : null);
        // A pattern that fails the safety checks is never run, even if it reached the database.
        return $entry !== null && $this->matcher->isSafe($entry['pattern']) ? $entry : null;
    }

    /**
     * Every usable rule, built-in first, for the builder's rule picker.
     *
     * @return list<RuleEntry>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->builtIn->all() as $code => $rule) {
            $out[] = $this->builtInEntry($code, $rule);
        }
        foreach ($this->savedRules() as $entry) {
            $out[] = $entry;
        }
        return array_values(array_filter($out, fn (array $e) => $this->matcher->isSafe($e['pattern'])));
    }

    public function reset(): void
    {
        $this->saved = null;
    }

    /**
     * @param array{label: string, group: string, pattern: string, message: string, example: string} $rule
     * @return RuleEntry
     */
    private function builtInEntry(string $code, array $rule): array
    {
        return [
            'ref' => $code,
            'label' => (string)__($rule['label']),
            'group' => (string)__($rule['group']),
            'pattern' => $rule['pattern'],
            'message' => (string)__($rule['message']),
            'example' => $rule['example'],
            'saved' => false,
        ];
    }

    /**
     * @return array<string, RuleEntry>
     */
    private function savedRules(): array
    {
        if ($this->saved === null) {
            $this->saved = [];
            foreach ($this->collectionFactory->create()->setOrder('label', 'ASC') as $rule) {
                $ref = self::SAVED_PREFIX . $rule->getData('code');
                $this->saved[$ref] = [
                    'ref' => $ref,
                    'label' => (string)$rule->getData('label'),
                    'group' => (string)__('Saved rules'),
                    'pattern' => (string)$rule->getData('pattern'),
                    'message' => (string)$rule->getData('message'),
                    'example' => (string)$rule->getData('example'),
                    'saved' => true,
                ];
            }
        }
        return $this->saved;
    }
}
