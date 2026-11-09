<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\FormState;

/**
 * What a matched (or unmatched) condition does to the item that carries it, registered in the `actions` argument
 * of Model\Condition\ActionPool. The browser needs a matching FuranFormBuilder.registry.conditionAction(code, fn)
 * registration, or it behaves differently from the server.
 *
 * @api
 */
interface ConditionActionInterface
{
    public const PARAM_VALUE = 'value';
    public const PARAM_OPTIONS = 'options';

    public function getCode(): string;

    public function getLabel(): string;

    /**
     * Item kinds the action can be set on.
     *
     * @return list<string> any of 'page', 'section', 'field', 'content'
     */
    public function targets(): array;

    /** The kind of parameter the builder asks for (self::PARAM_*), null for none. */
    public function needsParam(): ?string;

    /**
     * Called for every action of a condition with rules, whether or not the rules matched.
     * Record the effect on the state; never throw.
     */
    public function apply(FormState $state, string $targetId, mixed $param, bool $matched): void;
}
