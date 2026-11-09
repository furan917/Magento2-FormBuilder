<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RuleLibrary;

/**
 * @phpstan-import-type RuleEntry from RuleLibrary
 *
 * A format from the rule library. The value is the library reference, resolved on every check.
 */
class Preset extends AbstractRule
{
    public function __construct(private readonly RuleLibrary $library, private readonly PatternMatcher $matcher)
    {
    }

    public function getCode(): string
    {
        return 'preset';
    }

    public function getLabel(): string
    {
        return (string)__('Format');
    }

    public function getValueType(): string
    {
        return 'preset';
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        $entry = is_string($ruleValue) ? $this->library->find($ruleValue) : null;
        // Saved rules in use cannot be deleted and the schema rejects unknown references, so a missing entry
        // means the rule was removed outside the admin; skipping it beats blocking every submission.
        if ($entry === null) {
            return true;
        }
        return is_string($value) && $this->matcher->matches($entry['pattern'], $value);
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        $entry = is_string($ruleValue) ? $this->library->find($ruleValue) : null;
        return $entry !== null && $entry['message'] !== ''
            ? $entry['message']
            : (string)__('Please match the requested format.');
    }

    /**
     * The library entry behind a reference, or null if it no longer exists.
     *
     * @return RuleEntry|null
     */
    public function resolve(string $ref): ?array
    {
        return $this->library->find($ref);
    }
}
