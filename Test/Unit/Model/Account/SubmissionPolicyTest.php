<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Account;

use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubmissionPolicyTest extends TestCase
{
    public function testItAllowsDeleteUnlessTheFormTurnsItOff(): void
    {
        $policy = new SubmissionPolicy();

        $this->assertTrue($policy->canDelete([]));
        $this->assertTrue($policy->canDelete(['account_delete' => true]));
        $this->assertFalse($policy->canDelete(['account_delete' => false]));
    }

    /** @return array<string, array{array<string, mixed>, int, bool}> */
    public static function edits(): array
    {
        return [
            'new' => [['account_edit' => true], 0, true],
            'in progress' => [['account_edit' => true], 1, true],
            'answered' => [['account_edit' => true], 2, false],
            'closed' => [['account_edit' => true], 3, false],
            'setting off' => [['account_edit' => false], 0, false],
            'setting missing' => [[], 0, false],
        ];
    }

    #[DataProvider('edits')]
    public function testItAllowsEditOnlyWhenTheFormAllowsItAndTheSubmissionIsStillOpen(array $settings, int $status, bool $expected): void
    {
        $this->assertSame($expected, (new SubmissionPolicy())->canEdit($settings, $status));
    }

    public function testItTreatsOnlyNewAndInProgressAsEditableStatuses(): void
    {
        $policy = new SubmissionPolicy();

        $this->assertSame([true, true, false, false], array_map($policy->isEditableStatus(...), [0, 1, 2, 3]));
    }
}
