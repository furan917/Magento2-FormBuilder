<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/**
 * What the conditions decide for one pass over a form. Actions write to it; the Evaluator reads it back.
 *
 * @api
 */
class FormState
{
    /** @var array<string, bool> */
    private array $visible = [];
    /** @var array<string, bool> field id => required */
    private array $required = [];
    /** @var array<string, string> field id => value an action wants */
    private array $requestedValues = [];
    /** @var array<string, string> field id => default for an editable field */
    private array $defaults = [];
    /** @var array<string, list<string>> */
    private array $hiddenOptions = [];

    /**
     * @param array<string, string|array<int, string>> $values answers by field name, updated as the pass goes
     */
    public function __construct(private array $values = [])
    {
    }

    public function begin(string $id, bool $visible, bool $required = false): void
    {
        $this->visible[$id] = $visible;
        $this->required[$id] = $required;
    }

    public function hide(string $id): void
    {
        $this->visible[$id] = false;
    }

    public function isVisible(string $id): bool
    {
        return $this->visible[$id] ?? false;
    }

    public function require(string $id): void
    {
        $this->required[$id] = true;
    }

    public function setValue(string $id, string $value): void
    {
        $this->requestedValues[$id] = $value;
    }

    /** @param string[] $options */
    public function hideOptions(string $id, array $options): void
    {
        $this->hiddenOptions[$id] = array_values(array_unique(array_merge($this->hiddenOptions[$id] ?? [], $options)));
    }

    public function requestedValue(string $id): ?string
    {
        return $this->requestedValues[$id] ?? null;
    }

    /** @param string|array<int, string> $value */
    public function overwrite(string $name, string|array $value): void
    {
        $this->values[$name] = $value;
    }

    public function suggest(string $id, string $value): void
    {
        $this->defaults[$id] = $value;
    }

    public function forget(string $name): void
    {
        unset($this->values[$name]);
    }

    /** Nothing a hidden item asked for counts. */
    public function discard(string $id): void
    {
        unset($this->requestedValues[$id], $this->hiddenOptions[$id]);
        $this->required[$id] = false;
    }

    public function exempt(string $id): void
    {
        $this->required[$id] = false;
    }

    /** @return array<string, bool> */
    public function visibility(): array
    {
        return $this->visible;
    }

    /** @return array<string, bool> */
    public function required(): array
    {
        return $this->required;
    }

    public function isRequired(string $id): bool
    {
        return $this->required[$id] ?? false;
    }

    /** @return array<string, string|array<int, string>> answers of visible fields after the pass */
    public function values(): array
    {
        return $this->values;
    }

    /** @return array<string, string> */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /** @return array<string, list<string>> */
    public function hiddenOptions(): array
    {
        return $this->hiddenOptions;
    }
}
