<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** What the runner did with one delivery. */
enum RunOutcome
{
    /** Claimed and sent, or cancelled because its endpoint row is gone. */
    case Handled;

    /** Both slots of the endpoint were taken; the delivery is untouched. */
    case SlotBusy;

    /** Not run: lost the claim, or an internal failure. */
    case Skipped;
}
