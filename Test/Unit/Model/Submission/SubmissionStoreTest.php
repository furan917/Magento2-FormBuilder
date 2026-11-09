<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class SubmissionStoreTest extends TestCase
{
    private function resolve(?int $submissionStore, array $formStores, ?int $defaultStore = 1): int
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getStoreId')->willReturn($submissionStore);
        $form = $this->createStub(FormInterface::class);
        $form->method('getStoreIds')->willReturn($formStores);
        $default = null;
        if ($defaultStore !== null) {
            $default = $this->createStub(StoreInterface::class);
            $default->method('getId')->willReturn($defaultStore);
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getDefaultStoreView')->willReturn($default);

        return (new SubmissionStore($manager))->resolve($submission, $form);
    }

    public function testItUsesTheSubmissionsOwnStore(): void
    {
        $this->assertSame(3, $this->resolve(3, [2, 4]));
    }

    public function testItUsesTheFormsFirstAssignedStoreViewWhenTheSubmissionHasNone(): void
    {
        $this->assertSame(2, $this->resolve(null, [4, 2]));
    }

    public function testItTreatsTheAdminStoreAsNoStore(): void
    {
        $this->assertSame(2, $this->resolve(0, [2]));
    }

    public function testItUsesTheDefaultStoreViewForAFormOnAllStoreViews(): void
    {
        $this->assertSame(1, $this->resolve(null, [0]));
        $this->assertSame(1, $this->resolve(null, [0, 4]));
    }

    public function testItUsesTheDefaultStoreViewForAFormWithNoStoreViews(): void
    {
        $this->assertSame(1, $this->resolve(null, []));
    }

    public function testItReturnsZeroWhenThereIsNoDefaultStoreView(): void
    {
        $this->assertSame(0, $this->resolve(null, [0], null));
    }
}
