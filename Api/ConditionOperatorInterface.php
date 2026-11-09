<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/**
 * A condition operator, registered in the `operators` argument of Model\Condition\OperatorPool.
 * The browser needs a matching FuranFormBuilder.registry.operator(code, fn) registration, or it shows and hides
 * differently from the server.
 *
 * @api
 */
interface ConditionOperatorInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    /** Whether the builder offers (and the schema accepts) this operator on a field of the given type. */
    public function appliesTo(string $typeCode): bool;

    /** False hides the value input in the builder (for example "is empty"). */
    public function needsValue(): bool;

    /**
     * @param string|array<int|string, mixed> $actual raw answer as normalised by its field type, '' when missing
     * @param string $expected raw rule value, untrimmed
     * @param array<string, mixed> $field the source field definition, [] when it is not in the schema
     */
    public function evaluate(string|array $actual, string $expected, array $field): bool;
}
