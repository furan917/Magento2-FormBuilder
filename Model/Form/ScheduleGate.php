<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Decides whether a form accepts answers right now. The message comes from the store-view localised form:
 * pass it, or pass a `$localize` callback that is only called when the form is not open.
 */
class ScheduleGate
{
    public function __construct(private readonly DateTime $clock)
    {
    }

    /** @return array{state: string, message: string}|null null while the form is open */
    public function refusal(FormInterface $form, ?callable $localize = null): ?array
    {
        $settings = $form->getSettings();
        $state = Schedule::state($settings['open_from'] ?? '', $settings['close_at'] ?? '', (int)$this->clock->gmtTimestamp());
        if ($state === Schedule::OPEN) {
            return null;
        }
        if ($localize !== null) {
            $settings = $localize($form)->getSettings();
        }
        $key = $state === Schedule::NOT_YET ? 'not_open_message' : 'closed_message';
        $stock = $state === Schedule::NOT_YET ? 'This form is not open yet.' : 'This form is closed.';
        $message = trim((string)($settings[$key] ?? ''));

        return ['state' => $state, 'message' => $message !== '' ? $message : (string)__($stock)];
    }
}
