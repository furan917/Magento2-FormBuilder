# Extending

Everything in `Api/` (marked `@api`) is the supported surface; other classes can change. Extensions register through their own `di.xml`, `events.xml` and layout files.

## Pools

Pools are array arguments in `di.xml`. The item name must equal the item's `getCode()` (mismatches are skipped with a warning), and an item under a built-in name replaces the built-in.

```xml
<type name="Furan\FormBuilder\Model\Spam\SpamGuard">
    <arguments>
        <argument name="checks" xsi:type="array">
            <item name="my_check" xsi:type="object">Vendor\Module\Model\MyCheck</item>
        </argument>
    </arguments>
</type>
```

| Pool | Class and argument | Interface |
| --- | --- | --- |
| Field types | `Model\FieldType\Pool`, `types` | `Api\FieldTypeInterface` and the optional capability interfaces in `Api\FieldType\` |
| Validation rules | `Model\Validation\RulePool`, `rules` | `Api\ValidationRuleInterface` |
| Condition operators | `Model\Condition\OperatorPool`, `operators` | `Api\ConditionOperatorInterface` |
| Condition actions | `Model\Condition\ActionPool`, `actions` | `Api\ConditionActionInterface` |
| Prefill sources | `Model\Prefill\Sources`, `sources` | `Api\PrefillSourceInterface` |
| Spam checks | `Model\Spam\SpamGuard`, `checks` | `Api\SpamCheckInterface`, `Api\ValuesSpamCheckInterface` |
| Submission actions | `Model\Action\SubmissionActionPool`, `actions` | `Api\SubmissionActionInterface`, `Api\DeferrableActionInterface`, `Api\RetryableActionInterface` |
| Form settings contributors | `Model\Settings\ContributorPool`, `contributors` | `Api\FormSettingsContributorInterface` |
| Schema migrations | `Model\Schema\SchemaMigrator`, `migrations` | `Api\SchemaMigrationInterface` |

Other arguments extensions can fill:

| Class and argument | Value |
| --- | --- |
| `Model\FieldType\Hidden`, `sources` | Extra hidden field sources, filled in the browser by `registry.hiddenSource`. A code, or `['code' => 'crm.id', 'label' => 'CRM contact id']` for a builder label. Codes match `^[a-z][a-z0-9_.-]{0,63}$` |
| `Block\Form`, `scripts` and `styles` | Extra storefront files, see *Storefront JavaScript*. `Block\Widget\Form` inherits them |
| `Model\Validation\BuiltInRules`, `rules` | Ready-made formats: `code => [label, group, group_code, pattern, message, example]`. `group` is the translated heading; `group_code` is the stable code `PresetGroupsInterface` matches, and a rule without one fits every type |
| `Block\Adminhtml\Form\BuilderPhrases`, `phrases` | English texts for builder scripts, translated with `__()` and passed to the builder JS |

If a module providing a field type, operator, prefill source or setting is disabled, stored forms keep the value as saved and it is inert at runtime: the field is not shown, the operator is false and the source gives nothing.

A custom rule, operator or field type needs both a PHP pool item and a JS registration with the same code. In the browser unknown rules pass (the server decides) and unknown operators are false.

## Field types

`FieldTypeInterface` defines the code, label, category (`input`, `choice`, `advanced`), allowed rules, settings spec, `getTemplate()`, `getBuilderDefaults()`, `getInvalidMessage()`, `normalizeValue()` and `validateValue()`. Extend `Model\FieldType\AbstractFieldType` for defaults. Extra behaviour comes from capability interfaces, checked with `instanceof`:

| Interface | Adds |
| --- | --- |
| `ExportableTypeInterface` | CSV columns: `csvHeaders`, `csvCells`, `csvPlainColumns`. Every cell is still formula-guarded |
| `DisplayableTypeInterface` | `toText` (emails, My submissions, privacy export) and `toHtml` (admin view; escape every dynamic part). Both take an `Api\Data\DisplayContext` last: `forStore($storeId, $locale)` for the submission's store view, `forAdmin($locale)` for the admin view |
| `ClientMessagesInterface` | Keys merged into the field in the schema sent to the browser, e.g. `type_message` |
| `StorefrontConfigInterface` | Extra top-level keys in the storefront config, once per type present. Existing keys are never overridden |
| `StatsAwareTypeInterface` | Analytics chart kind (`KIND_*`). Without it the field only counts answers |
| `FreeTextTypeInterface` | Latest answers listed as free text in analytics. A free-text type with options (`hasOptions()`) treats them as suggestions: conditions cannot hide them, and `Model\FieldType\SuggestionMatcher::match()` stores a typed answer equal to an option's label, else its value, ignoring case, as that value. Suggest calls it from `normalizeValue()` |
| `ConsentTypeInterface` | Marker: records consent. Never required, never set by a condition, never drafted, blank under Login as Customer. The builder hides Required, Save in draft and Default |
| `ServerValueTypeInterface` | Marker: the server owns the value. A condition's `set_value` always overwrites it, a draft never stores it, and it cannot be required. The builder hides Required, Save in draft and Locked |
| `FixedValueTypeInterface` | `fixedValue(array $field)`: the answer the server stores whatever the browser sends, or `null` to keep the posted answer. Hidden fields with the Fixed value source use it |
| `EmailAddressTypeInterface` | Marker: the visitor's email address. Offered in the form's email pickers and as the notification reply-to |
| `PersonalByDefaultInterface` | The "contains personal data" flag defaults to on |
| `ConditionSourceInterface` | Whether conditions and "matches" rules may reference the type (default yes) |
| `RoutableTypeInterface` | Whether notification routes may match on it (default yes unless it is a file) |
| `RelativeDateSourceInterface` | Rules may compare the answer with a date relative to today; `relativeDateFormat()` is `Y-m-d` or `Y-m` |
| `AvailabilityInterface` | `isAvailable(?int $storeId = null)` for a type that depends on something optional. While unavailable it is hidden from the palette and not rendered or validated; stored fields are kept |
| `AdminViewTypeInterface` | `adminViewKind(array $field)`: how the admin view and My submissions lay out the answer, one of the `KIND_*` constants or `null` for plain text. `KIND_COUNTRY` and `KIND_CALENDAR_*` answers are shown in the reader's locale: the admin's in the admin view, the submission's store view elsewhere |
| `RenderHintsInterface` | `renderHints(array $field, int $storeId)`: data for the storefront template as `$item['render']`. Per form and store view, never per visitor: pages are cached |
| `StatsLabelInterface` | `statsLabel(string $value, array $field)`: the chart label for a stored value, e.g. a country name for an ISO code |
| `MaxLengthInterface` | `maxLength()`: the longest answer the type keeps. The type's `normalizeValue()` cuts longer text; `AbstractFieldType` does both. A `max_length` rule above it and a longer Default value are refused at save |
| `DefaultValueInterface` | `defaultInput()` (`null` hides Default and clears it on save, else `text`, `option`, `number`, `date`, `datetime-local`, `month`, `time` or `color`), `defaultChoices()` (value => label for the picker when the field has no options, such as countries) and `isValidDefault(string $default, array $field)` (checked at save). `AbstractFieldType` provides free text checked by `validateValue()` |
| `SettingsCheckInterface` | `settingsErrors(array $field)`: translated problems with the saved settings that the spec keys cannot express, each a fragment such as `the start is after the end`. Each one is refused at save as `Field "<label>": <fragment>.` |
| `PresetGroupsInterface` | `presetGroups()`: the rule library group codes (`postcode`, `phone`, `finance`, `personal`, `store`, `content`) whose formats the type's `preset` rule accepts. Saved rules and library rules without a `group_code` always apply, so `[]` means saved rules only. Without it every format applies |
| `BuilderControlsInterface` | `builderControls()`: builder display choices only. `placeholder`, `help`, `width`, `css_class`, `autocomplete` and `option_description` set to false hide the control; `options_title` names the Options tab and `option_add_label` its add button. A missing key keeps the control. Required, Save in draft and Default follow `ServerValueTypeInterface`, `ConsentTypeInterface`, `isFile()` and `DefaultValueInterface` |

Settings spec entries are `name => [type, default, min, max, values, value_labels, label, translatable, hint, visible_when, format, not_above, exclusive_min, no_limit]`, with `type` one of `int`, `number`, `string`, `bool`, `enum`, `list`. The builder draws a control for each. `hint` is a line of help under the control. `format` `mask` draws a mask picker, `date` a date input and `email_field` a picker of the form's email fields. `format` `date` values must be real dates and `step` values `any` or a number above 0. `not_above` names a sibling setting this one may not exceed, compared when both are set and the sibling is not at its `no_limit` value. `exclusive_min` is a number the value must be greater than. The builder and the save run the same checks. An `int` or `number` setting with `values` is a dropdown. `visible_when` maps another setting to the values that show this one, for example `['source' => ['query']]`. A key starting with `@` reads the field itself: `['@prefill' => ['']]` shows the setting only while Prefill is empty.

### Worked example

A "satisfaction" field in a `Vendor_FormRating` module: a score from 1 to a configurable maximum plus a short comment, stored as `{"score": "4", "comment": "..."}`.

`etc/di.xml` registers the type and its storefront script:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Furan\FormBuilder\Model\FieldType\Pool">
        <arguments>
            <argument name="types" xsi:type="array">
                <item name="satisfaction" xsi:type="object">Vendor\FormRating\Model\FieldType\Satisfaction</item>
            </argument>
        </arguments>
    </type>
    <type name="Furan\FormBuilder\Block\Form">
        <arguments>
            <argument name="scripts" xsi:type="array">
                <item name="satisfaction" xsi:type="string">Vendor_FormRating::js/satisfaction.js</item>
            </argument>
            <argument name="styles" xsi:type="array">
                <item name="satisfaction" xsi:type="string">Vendor_FormRating::css/satisfaction.css</item>
            </argument>
        </arguments>
    </type>
</config>
```

The type. It implements export, display, client messages and a stats kind; the answer is not a single number, so analytics gets `KIND_NONE`. Return `KIND_DISCRETE` instead if the type stored only the score as a string.

```php
<?php
declare(strict_types=1);

namespace Vendor\FormRating\Model\FieldType;

use Furan\FormBuilder\Api\Data\DisplayContext;
use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface;
use Furan\FormBuilder\Api\FieldType\ExportableTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;
use Magento\Framework\Escaper;

class Satisfaction extends AbstractFieldType implements
    ExportableTypeInterface,
    DisplayableTypeInterface,
    ClientMessagesInterface,
    StatsAwareTypeInterface
{
    public function getCode(): string
    {
        return 'satisfaction';
    }

    public function getLabel(): string
    {
        return (string)__('Satisfaction');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function getSettingsSpec(): array
    {
        return ['max' => [
            'type' => 'int', 'default' => 5, 'min' => 3, 'max' => 10, 'label' => (string)__('Highest score'),
        ]];
    }

    public function getTemplate(): string
    {
        return 'Vendor_FormRating::item/satisfaction.phtml';
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (!is_array($raw)) {
            return null;
        }
        $score = $this->clean($raw['score'] ?? null, 3);
        $comment = $this->clean($raw['comment'] ?? null, 500);

        return $score === '' && $comment === '' ? null : ['score' => $score, 'comment' => $comment];
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        $score = is_array($value) ? (string)($value['score'] ?? '') : '';
        if (preg_match('/^[0-9]{1,2}$/D', $score) && (int)$score >= 1 && (int)$score <= $this->max($field)) {
            return null;
        }

        return $this->getClientMessages($field)['type_message'];
    }

    public function getClientMessages(array $field): array
    {
        return ['type_message' => (string)__('Please choose a score from 1 to %1.', $this->max($field))];
    }

    public function csvHeaders(array $field): array
    {
        $label = (string)($field['label'] ?? '');

        return [$label . ' (score)', $label . ' (comment)'];
    }

    public function csvCells(array $field, mixed $value): array
    {
        return [$this->part($value, 'score'), $this->part($value, 'comment')];
    }

    public function csvPlainColumns(array $field): array
    {
        return [];
    }

    public function toText(array $field, mixed $value, DisplayContext $context): string
    {
        $score = $this->part($value, 'score');
        $comment = $this->part($value, 'comment');
        if ($score === '') {
            return $comment;
        }

        return $score . '/' . $this->max($field) . ($comment === '' ? '' : ': ' . $comment);
    }

    public function toHtml(array $field, mixed $value, Escaper $escaper, DisplayContext $context): string
    {
        return $escaper->escapeHtml($this->toText($field, $value, $context));
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_NONE;
    }

    private function max(array $field): int
    {
        return min(10, max(3, (int)($field['settings']['max'] ?? 5)));
    }

    private function part(mixed $value, string $key): string
    {
        return is_array($value) && is_scalar($value[$key] ?? null) ? (string)$value[$key] : '';
    }

    private function clean(mixed $value, int $length): string
    {
        $text = is_scalar($value) ? (preg_replace('/[\x00-\x1F\x7F]/', '', (string)$value) ?? '') : '';

        return mb_substr(trim($text), 0, $length);
    }
}
```

The template, `view/frontend/templates/item/satisfaction.phtml`. It reuses the module's wrapper partials, so label, help, error and the `data-ffb-*` attributes match the built-in fields. Names must be `fields[<name>]` with a key per part:

```php
<?php
/** @var \Magento\Framework\View\Element\Template $block */
/** @var \Magento\Framework\Escaper $escaper */
$form = $block->getData('parent_block');
extract($form->getItemContext((array)$block->getData('item'), $escaper), EXTR_SKIP);
$max = min(10, max(3, (int)($settings['max'] ?? 5)));
?>
<?= /* @noEscape */ $form->renderPartial($block, 'item/_open.phtml', ['group' => true]) ?>
    <div class="ffb-rating" role="radiogroup">
<?php for ($i = 1; $i <= $max; $i++): ?>
        <label class="ffb-option"><input<?= /* @noEscape */ $attrs([
            'class' => 'ffb-choice',
            'type' => 'radio',
            'id' => $controlId . '-' . $i,
            'name' => $base['name'] . '[score]',
            'value' => (string)$i,
        ]) ?>><span><?= (int)$i ?></span></label>
<?php endfor; ?>
    </div>
    <input<?= /* @noEscape */ $attrs([
        'class' => 'ffb-input',
        'type' => 'text',
        'name' => $base['name'] . '[comment]',
        'maxlength' => 500,
        'aria-label' => (string)__('Comment'),
    ]) ?>>
<?= /* @noEscape */ $form->renderPartial($block, 'item/_close.phtml', ['group' => true]) ?>
```

The storefront script, `view/frontend/web/js/satisfaction.js`. It runs after `ffb-core.js`, `ffb-types.js`, `ffb-drafts.js` and `ffb-submit.js`, and before `ffb-form.js`. `normalize` must mirror `normalizeValue` (blank is an empty string); `read` and `write` move the value between the markup and the form:

```js
(function () {
  var registry = window.FuranFormBuilder.registry;
  var control = function (wrapper, selector) { return wrapper.querySelector(selector); };

  registry.fieldType('satisfaction', {
    normalize: function (value) {
      var v = value && typeof value === 'object' ? value : {};
      var score = String(v.score || '').trim();
      var comment = String(v.comment || '').trim().slice(0, 500);
      return score === '' && comment === '' ? '' : { score: score, comment: comment };
    },
    intrinsicDefault: function (value, field) {
      var max = Number((field.settings || {}).max) || 5;
      return /^[0-9]{1,2}$/.test(value.score) && +value.score >= 1 && +value.score <= max
        ? null : 'Please choose a score.';
    },
    read: function (wrapper) {
      var picked = control(wrapper, 'input[type="radio"]:checked');
      return { score: picked ? picked.value : '', comment: control(wrapper, 'input[type="text"]').value };
    },
    write: function (wrapper, value) {
      var v = value && typeof value === 'object' ? value : {};
      wrapper.querySelectorAll('input[type="radio"]').forEach(function (radio) {
        radio.checked = radio.value === String(v.score || '');
      });
      control(wrapper, 'input[type="text"]').value = String(v.comment || '');
    }
  });
})();
```

A type can also add `summary(field, wrapper, value, optionLabel)`, which returns the text a review step (summary block) shows for the answer; without it the generic text is used. A fifth argument, `formatDate(value)`, formats a `Y-m-d` date in the store locale.

The server message from `ClientMessagesInterface` (`type_message`) replaces the English text returned by `intrinsicDefault`, so the visitor sees the translated one.

The builder shows a plain mock control for a custom type. `view/adminhtml/layout/furanform_form_edit.xml` adds a script, and the script supplies a preview with `builderRegistry.preview`:

```xml
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <head>
        <script src="Vendor_FormRating::js/builder.js" defer="defer"/>
    </head>
</page>
```

```js
(function () {
  var ns = window.FuranFormBuilder = window.FuranFormBuilder || {};
  var registry = ns.builderRegistry = ns.builderRegistry || { queue: [] };
  var preview = function (field) {
    var max = Number((field.settings || {}).max) || 5;
    var box = document.createElement('div');
    for (var i = 1; i <= max; i++) {
      var step = document.createElement('span');
      step.textContent = String(i);
      box.appendChild(step);
    }
    return box;
  };
  if (registry.preview) {
    registry.preview('satisfaction', preview);
  } else {
    registry.queue.push(['preview', 'satisfaction', preview]);
  }
})();
```

The rest of the example is plain Magento:

- Translations: add the phrases (`Satisfaction`, `Highest score`, `Comment`, the message) to the module's `i18n/*.csv`. Spec labels are translated on the server.
- If the script or style loads from another host (a CDN, a widget), add the host in `etc/csp_whitelist.xml`. Files loaded through `scripts` and `styles` come from the module's own static directory and need no entry. Storefront and builder code must not use inline `<script>`; pass data in `type="application/json"` blocks.
- Run `bin/magento setup:di:compile` and `setup:static-content:deploy` as for any module.

```xml
<csp_whitelist xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Csp:etc/csp_whitelist.xsd">
    <policies>
        <policy id="script-src">
            <values>
                <value id="vendor_form_rating" type="host">cdn.example.com</value>
            </values>
        </policy>
    </policies>
</csp_whitelist>
```

## Validation rules, operators, prefill sources

- **Rule**: `ValidationRuleInterface` (`getValueType()` is one of `none`, `int`, `number`, `string`, `regex`, `date`, `field`; `validate($value, $ruleValue, $context)` with `$context['values']`). Browser side: `registry.rule(code, fn(value, ruleValue, field, values) -> bool, messageFn(ruleValue))`. A field type lists the rule codes it allows in `getAllowedRules()`.
- **Operator**: `ConditionOperatorInterface` (`appliesTo($typeCode)` decides where the builder offers it, `needsValue()` hides the value input, `evaluate($actual, $expected, $field)` gets raw values). Browser side: `registry.operator(code, fn(rawActual, rawExpected, sourceField) -> bool)`. The Normalizer accepts a rule only if its operator is pooled and applies to the referenced field's type.
- **Condition action**: `ConditionActionInterface` (`targets()` lists the item kinds it can sit on, `needsParam()` is `value`, `options` or null, `apply(Api\Data\FormState, $targetId, $param, $matched)` runs whether or not the rules matched). Built-ins are `show`, `hide`, `require`, `set_value` and `hide_options`. A pooled action also needs `registry.conditionAction` in the browser.
- **Prefill source**: `PrefillSourceInterface` (`getGroup()` is `customer` or `product`, `suitsType()`, `resolve(Api\Data\PrefillContextInterface)` returns a string, an address array or null). Product sources are always locked and re-read on submit.
- **Hidden field source**: add the code to `Hidden`'s `sources` argument and call `registry.hiddenSource(code, fn(field) -> string)`. Without a registration, or when the function returns an empty string, the field keeps its default value. The value comes from the browser, so treat it as visitor input.

## Spam checks

`SpamCheckInterface::check($input, $form)` runs before validation and `ValuesSpamCheckInterface::checkValues($form, $values, $input)` after it, before files are stored. `$input` is an immutable `Api\SubmissionInput`, not an HTTP request. Its `userAgent` and `referrer` are for spam checks only: never store, log or forward them. Both return an `Api\SpamResult`:

- `SpamResult::pass()`
- `SpamResult::reject($code, ?Phrase $message, $httpCode = 400)`: the visitor sees the message (a generic one when null).
- `SpamResult::silent($code)`: the visitor gets the normal success response, nothing is stored and no email is sent. Use it when a rejection would help a bot probe.

Checks run in pool order and the first non-pass wins. Log the check code, never the values.

The built-in time trap answers 425 (`SpamResult::reject($code, $message, 425)`) to a token younger than **Minimum Seconds Before Submit**. The token response carries `notBefore`, so the browser waits that long, shows "Sending..." and retries once after a 425.

## Submission actions

`SubmissionActionInterface`: `supports(ActionContext)` and `execute($form, $submission, $values, ActionContext)`. `ActionContext` has `isEdit`, `storeId`, `customerId` and `attempt`. Edits run only actions that support `isEdit`. Exceptions are logged and never reach the visitor.

Register actions under the `actions` argument of `Furan\FormBuilder\Model\Action\SubmissionActionPool`. Actions run in pool order and queued actions are found by key, so keep keys stable.

- Built-ins are `notification` and `autoresponder` (deferrable, retryable only when the mail server refused the message) and the `newsletter` action (deferrable, not retryable, skips edits).
- `DeferrableActionInterface::isApplicable($form)`: with **Run Submission Actions in the Background** on, the action runs from the `furan_formbuilder.action` queue in the submission's store view, with stored values only (no request, no files).
- `RetryableActionInterface`: `isRetryable(\Throwable $failure)` decides which failures are retried and `maxAttempts()` (1 to 5) how often; cron retries after 1 min, 5 min, 30 min, 2 h and 6 h. Retry only failures where the action had no effect. `notification` and `autoresponder` retry when the mail server refused the message (`MailNotSentException`), never after a send, and a retried autoresponder does not count against its daily cap again.

## Services

Use `Api\FormRepositoryInterface` and `Api\SubmissionRepositoryInterface` (data objects `Api\Data\FormInterface`, `Api\Data\SubmissionInterface`) rather than resource models. Save forms through `Api\FormManagementInterface::save()`, which migrates and normalises the schema, keeps ACL-protected settings, validates and throws `Api\Exception\InvalidSchemaException`; the repository's `save()` skips all of that. Retry storage sits behind `Api\ActionRetrySchedulerInterface`.

Both repositories have `getList(SearchCriteriaInterface)`, which returns one keyset page (`Api\Data\FormSearchResultsInterface`, `Api\Data\SubmissionSearchResultsInterface`). `currentPage` above 1 throws `InputException`: page on with a `form_id` or `submission_id` `gt` filter past the last id, sorted by that id (`lt` when sorting `DESC`). `pageSize` defaults to 100 and is capped at 500. The id is always the last sort. Each filter group holds one filter, and filter fields, conditions and sort fields come from an allowlist listed on the interface; anything else throws `InputException`. Submissions sort by `submission_id` only, `form_id`, `status`, `store_id`, `customer_id` and `is_read` take `eq` only, and an `updated_at` filter needs an `eq` filter on `form_id`, `status`, `store_id`, `customer_id` or `is_read`. `total_count` is the number of items returned, and `getHasMore()` is true when more rows follow.

`FormManagementInterface::save()` takes an `Api\FormWriteContext`. For a logged-in admin use `FormWriteContext::forAdmin($authorization, $adminId)`, so their ACL decides which protected settings they may change. `FormWriteContext::allowAll()` skips every ACL check: CLI and trusted code only, never a request path.

## Form settings contributors

A contributor adds form settings under `settings.ext.<code>`: `getCode()` (`^[a-z][a-z0-9_]{0,31}$`, equal to the item name), `getLabel()`, `getSpec()` (same shape as a field type's spec) and `getAclResource()` (without it the panel is read-only). Values are plain JSON visible in the builder and exports, so never store secrets. Read them with `Model\Settings\FormSettings::extension($form, $code)`.

## Events

Register observers in `events.xml`.

| Event | Payload | When |
| --- | --- | --- |
| `furan_formbuilder_submission_validate_after` | `form`, `values` (a read-only `DataObject` snapshot), `errors` (a `DataObject`; add `name => message`) | Validation passed, before files and spam value checks. New submissions and edits. Any error gives 422; a name that is not a field becomes the general message. An exception here gives 500 and nothing is stored |
| `furan_formbuilder_submission_after` | `form`, `submission` (has its id once stored), `submission_id` (null when not stored), `values` | New submission stored, before the actions pool. A failing observer is logged and does not change the response |
| `furan_formbuilder_submission_updated` | `form`, `submission` (with id, customer id and status), `values` | Survey or My submissions edit committed. The actions pool runs only for actions that support edits |
| `furan_formbuilder_submission_status_changed` | `submission_id`, `form_id`, `old_status`, `new_status` (integers 0 to 3), `source` (`admin`, `customer` or `system`) | A status really changed: status save, mass status (one event per changed row), admin reply (answered) and a customer reply that reopens an answered submission. Observer failures are logged |
| `furan_formbuilder_thread_message_added` | `submission_id`, `form_id`, `author` (`admin`, `customer` or `guest`), `message_id` | A thread message was stored. Observer failures are logged |
| `furan_formbuilder_storefront_config_build` | `form`, `store_id`, `config` (a `DataObject`) | The storefront config of a form is built. `formId`, `submitUrl`, `tokenUrl` and `schema` are restored afterwards |
| `furan_formbuilder_webhook_send_before` | `endpoint` (read-only, never the secret), `event`, `delivery_id`, `envelope` (a copy of the body), `veto` | Before a webhook is signed and sent. `$veto->setData('veto', true)` cancels it. The signed bytes are fixed, so changing `envelope` has no effect. A throwing observer is logged and the delivery retried |

Storefront HTML is full-page cached per form and store view, so a `storefront_config_build` observer may add per-form or per-store data only, never per-visitor data. Visitor data belongs in the token request.

The models also fire Magento's automatic events `furan_formbuilder_form_*` and `furan_formbuilder_submission_*`. Mass delete, retention, GDPR erase and account deletion delete in batches (`BatchDeleter`), so batch deletes do not fire the delete events, and status changes are direct updates. Use `furan_formbuilder_submission_status_changed` for statuses.

## Webhook transport

`Api\WebhookTransportInterface::send(SafeUrl $url, RequestPolicy $policy, array $headers, string $body): HttpResult` (all three in `Api\Data`) posts one signed webhook; replace it with a `preference` to use your own client. The kill switch and `UrlPolicy::validateForSend` run first, but the transport must still resolve, pin, refuse private targets and never follow redirects. Send headers and body unchanged and return an `HttpResult` with an error class rather than throwing. To veto a delivery, observe `furan_formbuilder_webhook_send_before` instead.

## Storefront JavaScript

Add scripts with the `scripts` argument of `Block\Form` (`Vendor_Module::js/file.js` or `['file' => ..., 'sortOrder' => 10]`; `styles` likewise). They load deferred after `ffb-core.js`, `ffb-types.js`, `ffb-drafts.js` and `ffb-submit.js`, and before `ffb-form.js`. A script that runs earlier can pre-create `FuranFormBuilder.registry = {queue: [['rule', code, fn]]}`. `ffb-core.js` exports `sameOrigin` and `safeAsset` for URL checks, and `ffb-types.js` the value and validation helpers on `FuranFormBuilder.types`. If a required script fails to load, the form stays plain HTML with a console warning.

Codes match `^[a-z][a-z0-9_.-]{0,63}$`. Registering a built-in code throws.

| Call | Purpose |
| --- | --- |
| `registry.rule(code, fn, messageFn)` | Validation rule |
| `registry.operator(code, fn)` | Condition operator |
| `registry.fieldType(code, hooks)` | `normalize(value, field)`, `intrinsicDefault(value, field)`, `validate(value, field, values)`, `read(wrapper)`, `write(wrapper, value)`. Hooks merge by key across calls |
| `registry.hiddenSource(code, fn)` | Value for a hidden field source |
| `registry.conditionAction(code, fn)` | Condition action `fn(state, targetId, param, matched)`; `state` offers `hide()`, `require()`, `setValue(value)`, `hideOptions(list)`. Runs whenever the condition has rules, so it needs the pooled PHP action too. A `fieldType` may add `hideOptions(wrapper, values)` to hide choice options its own way (called with `[]` to show them all again) |

DOM events are `CustomEvent`s that bubble from the form root (`[data-ffb-root]`). `detail.formId` is always present; answers are never in `detail`.

| Event | Detail | When |
| --- | --- | --- |
| `ffb:ready` | | The form is initialised |
| `ffb:pagechange` | `page`, `pages` | The visible page changed (1-based among visible pages) |
| `ffb:invalid` | `fields` (names) | Client validation failed on Next or submit, or the server answered 422 |
| `ffb:submit` | | Client validation passed, before the token and submit requests. Cancelable: `preventDefault()` stops the submit |
| `ffb:success` | `reference` when the response has one | The success state is shown. The server has already replaced `{{reference}}` in the success message |

## Builder JavaScript

`FuranFormBuilder.builderRegistry` is loaded first on the edit page.

- `builderRegistry.preview(typeCode, fn(field) -> Node)` replaces the mock control of a custom field type on the canvas.
- `builderRegistry.inspectorControl(settingType, fn(spec, value, onChange) -> Node)` draws a settings entry whose `type` is not one of `int`, `number`, `bool`, `enum`, `list`, `string`.

Built-in codes throw, and a hook that throws or returns a non-Node falls back to the generic output. Scripts in the `furanform_form_edit` layout handle may run before the registry exists; pre-create it with a `queue` as in the example. Builder texts go through `BuilderPhrases` (`phrases`).

## Form schema, version 1

The schema is stored in `schema_json` and is what the builder, export and import exchange. Unknown keys are dropped on save.

```json
{
  "version": 1,
  "pages": [
    {
      "id": "p_ab12cd",
      "title": "Your details",
      "condition": null,
      "items": [
        {
          "id": "f_k3j9x2", "kind": "field", "type": "text", "name": "first_name", "label": "First name",
          "placeholder": "", "help": "", "default": "", "width": "half", "required": true,
          "rules": [{"rule": "min_length", "value": 2, "message": ""}],
          "options": [], "settings": {}, "condition": null
        },
        {
          "id": "s_77bb02", "kind": "section", "title": "Address", "description": "",
          "condition": {"action": "show", "match": "all", "rules": [{"field": "first_name", "operator": "not_empty", "value": ""}]},
          "items": [
            {"id": "c_99xa01", "kind": "content", "type": "heading", "text": "Where do you live?", "level": 2, "width": "full", "condition": null}
          ]
        }
      ]
    }
  ]
}
```

- Ids are `p_`, `f_`, `c_` or `s_` plus 4 to 16 lowercase letters or digits, unique in the form. Field names are `^[a-z][a-z0-9_]{0,63}$`, unique, and cannot be `form_key`, `form_id` or start with `ffb_`.
- Items are `field`, `content` (heading, paragraph, divider, spacer and others) or `section`. Sections hold fields and content only. Width is `full`, `two_thirds`, `half` or `third`.
- A condition holds `actions: [{action, param?}]`, `match` and `rules`, and may only reference earlier fields. A rule is `{field, operator, value}`, `{field, operator, value_field}` or `{field, operator, value_date: {days}}`, or a nested group `{match, rules}`. A page also has a `skip` slot, `{match, rules, target}`.
- Other keys on a field (`personal`, `prefill`, `locked`, `autocomplete`, `css_class`, `draft`) follow the same rules: normalised on save, defaults filled in.

`Normalizer::SCHEMA_VERSION` is the version written on save. To change the format, bump it and register an `Api\SchemaMigrationInterface` under the `migrations` argument of `Model\Schema\SchemaMigrator`, keyed by `fromVersion()`. A migration turns N into N + 1 and must keep page and item ids, because translations are keyed by them. Forms upgrade on load (`upgrade()`, or `upgradeJson()` which never throws). A newer version than the code, or a missing step, throws `UnsupportedSchemaVersionException`: the storefront treats the form as missing and the builder opens it read-only.
