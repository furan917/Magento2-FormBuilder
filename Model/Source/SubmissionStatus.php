<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Source;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

class SubmissionStatus implements OptionSourceInterface
{
    /** Stable machine codes for payloads and exports. */
    public const CODES = [
        SubmissionInterface::STATUS_NEW => 'new',
        SubmissionInterface::STATUS_IN_PROGRESS => 'in_progress',
        SubmissionInterface::STATUS_ANSWERED => 'answered',
        SubmissionInterface::STATUS_CLOSED => 'closed',
    ];

    /**
     * @return array<int, array{value: int|string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        $options = [];
        foreach ($this->labels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }

    public function isValid(int $status): bool
    {
        return isset($this->labels()[$status]);
    }

    /** Null when the raw request value is not a known status. */
    public function parse(mixed $raw): ?int
    {
        if (!is_string($raw) || !ctype_digit($raw) || !$this->isValid((int)$raw)) {
            return null;
        }
        return (int)$raw;
    }

    public function getLabel(int $status): Phrase
    {
        return $this->labels()[$status] ?? __('Unknown');
    }

    /**
     * @return array<int, Phrase>
     */
    private function labels(): array
    {
        return [
            SubmissionInterface::STATUS_NEW => __('New'),
            SubmissionInterface::STATUS_IN_PROGRESS => __('In progress'),
            SubmissionInterface::STATUS_ANSWERED => __('Answered'),
            SubmissionInterface::STATUS_CLOSED => __('Closed'),
        ];
    }
}
