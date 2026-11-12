<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: builder display choices for the type's General and Options tabs.
 *
 * Keys: `placeholder`, `help`, `width`, `css_class`, `autocomplete`, `option_description` (false hides the control);
 * `options_title` and `option_add_label` (translated captions of the Options tab and its add button).
 * A missing key keeps the control. Required, Save in draft and Default come from the server capabilities;
 * the Default input kind from DefaultValueInterface.
 *
 * @api
 */
interface BuilderControlsInterface
{
    /**
     * @return array<string, bool|string>
     */
    public function builderControls(): array;
}
