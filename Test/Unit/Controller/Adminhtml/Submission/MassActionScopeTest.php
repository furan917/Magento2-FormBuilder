<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\Submission\MassDelete;
use Furan\FormBuilder\Controller\Adminhtml\Submission\MassStatus;
use Furan\FormBuilder\Model\Clock;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Submission\GridSelection;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;

class MassActionScopeTest extends SubmissionControllerTestCase
{
    /** @return array<string, array{mixed, array<string, int>}> */
    public static function formIds(): array
    {
        return [
            'scoped' => ['12', ['form_id' => 12]],
            'none' => [null, []],
            'zero' => ['0', []],
            'negative' => ['-3', []],
            'not digits' => ['12 OR 1', []],
            'array' => [['12'], []],
        ];
    }

    private function given(mixed $formId, string $status = '2'): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key) => ['form_id' => $formId, 'status' => $status, 'selected' => ['4'], 'excluded' => 'false'][$key] ?? null
        );
    }

    private function expectRedirect(array $params): void
    {
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/', $params)->willReturnSelf();
    }

    /** @param list<list<int>>|\Throwable $batches */
    private function selection(array|\Throwable $batches): GridSelection
    {
        $selection = $this->createStub(GridSelection::class);
        $selection->method('batches')->willReturnCallback(static function () use ($batches) {
            if ($batches instanceof \Throwable) {
                throw $batches;
            }
            yield from $batches;
        });
        return $selection;
    }

    private function massStatus(array|\Throwable $batches): MassStatus
    {
        $writer = $this->createStub(StatusWriter::class);
        $writer->method('change')->willReturn(1);
        return new MassStatus($this->context, $this->selection($batches), new SubmissionStatus(), $writer, new Clock());
    }

    private function massDelete(array|\Throwable $batches): MassDelete
    {
        $deleter = $this->createStub(BatchDeleter::class);
        $deleter->method('deleteBatch')->willReturn(1);
        return new MassDelete($this->context, $this->selection($batches), $deleter, new Clock());
    }

    #[DataProvider('formIds')]
    public function testItKeepsTheFormScopeAfterAMassStatusChange(mixed $formId, array $params): void
    {
        $this->given($formId);
        $this->expectRedirect($params);

        $this->massStatus([[4]])->execute();
    }

    #[DataProvider('formIds')]
    public function testItKeepsTheFormScopeWhenTheMassStatusIsInvalid(mixed $formId, array $params): void
    {
        $this->given($formId, '99');
        $this->expectRedirect($params);

        $this->massStatus([[4]])->execute();
    }

    #[DataProvider('formIds')]
    public function testItKeepsTheFormScopeWhenAMassStatusChangeFails(mixed $formId, array $params): void
    {
        $this->given($formId);
        $this->expectRedirect($params);

        $this->massStatus(new LocalizedException(__('Nothing selected.')))->execute();
    }

    #[DataProvider('formIds')]
    public function testItKeepsTheFormScopeAfterAMassDelete(mixed $formId, array $params): void
    {
        $this->given($formId);
        $this->expectRedirect($params);

        $this->massDelete([[4]])->execute();
    }

    #[DataProvider('formIds')]
    public function testItKeepsTheFormScopeWhenAMassDeleteFails(mixed $formId, array $params): void
    {
        $this->given($formId);
        $this->expectRedirect($params);

        $this->massDelete(new LocalizedException(__('Nothing selected.')))->execute();
    }
}
