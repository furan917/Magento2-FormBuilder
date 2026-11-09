# Building forms

Forms live under **Content → Form Builder → Forms**. Each active form is served at `/forms/<identifier>` in the store views it is assigned to (others get a 404), and the **Form Builder Form** widget embeds it in CMS pages, blocks and Page Builder. The `forms` prefix is set under [General → URL Prefix](operations.md#configuration).

- The Forms grid has the mass actions Delete, Enable and Disable.
- **Preview** in the builder opens an active form at its URL, and an inactive one on a signed storefront link valid for 2 hours, with a notice. Answers cannot be sent from it.
- **Delete form** in the builder toolbar deletes the form and all its submissions (needs **Delete Forms**).

## Fields and layout

- **Inputs**: text (with prefix/suffix), text with suggestions, list, textarea, email, tel, url, number, date, date range, time, datetime, month, colour, hidden, address.
- **Choices**: select, multiselect, radio, checkboxes, checkbox, toggle, consent, newsletter, cards, country, star rating, opinion scale, Likert matrix, range.
- **Advanced**: map pin, file upload.
- **Presets**: full name, email with confirmation, phone, address, yes/no, date of birth, NPS and terms consent.
- **Layout**: pages with a progress indicator, sections, headings, paragraphs, rich text, notices, a review step, dividers and spacers on a 6-column grid.

Drafts survive a page refresh (session storage by default, local storage or off per form). With local storage, logged-in customers' drafts follow them between devices. A draft is dropped when the form's draftable fields change; a label change keeps it.

Uploads take one file per field, checked by extension allowlist, MIME type and a scan for embedded code, with per-field, per-request and per-form hourly size limits. Files are stored outside `pub/` under random names.

## Conditions

Add a condition to a page, section, field or content block, or a **Skip ahead** to a page.

- A condition matches all or any of its rules, with nested groups up to 3 levels and 25 rules and groups in total.
- A rule compares an earlier field with a typed value, another earlier field, or a number of days from today (date fields, -3650 to 3650). For a choice field the value is picked from its options, and `is one of` ticks several; other fields take `is one of` entries one per line.
- Actions: show, hide, require, set value and hide options. Set value fills the field in the browser; on the server it only overwrites locked and hidden fields.
- **Skip ahead** jumps to a later page. Fields on skipped pages are empty and not required.
- Today is the store view's date. The browser and the server evaluate the same rules, and the server checks again on submit.

Conditions also route notifications to extra recipients and decide whether the notification or autoresponder is sent.

## Validation rules

Fields take length, format, number, date and match-another-field rules, plus ready-made formats: postcodes (UK, US, CA, IE, NL, AU), phone numbers, sort code, IBAN, VAT, company and NI numbers, order and coupon codes, and "no links".

**Content → Form Builder → Validation rules** holds your own saved rules, each with a name, pattern, message, optional example and a tester. Editing a rule updates every form using it. A rule in use cannot be deleted.

- Patterns match the whole answer, case-sensitive, up to 256 characters.
- Patterns that can run slowly (nested repeats such as `(a+)+`, overlapping repeats such as `\d*\d*`, back-references) or that browsers cannot read are refused.
- Browsers skip pattern checks on answers over 1,000 characters; the server always runs them.

## Input masks

Text and phone fields take a mask: a preset format or a custom pattern of up to 40 characters (`9` digit, `a` letter, `A` upper-case letter, `*` either, anything else literal, e.g. `99-99-99`).

The browser formats the answer when the visitor leaves the field, never while typing. The server formats the stored answer the same way. A mask that would break the field's own format rule is refused on save.

## Field groups

Choose **Save as group** on a field or a section; **Insert a field group...** adds a copy to any form. Later edits to a group do not change forms that already contain it.

- Inserted items get new ids, and a clashing field name gets `_2`, `_3` and so on, with the group's rules following the rename.
- Rules pointing outside the saved selection are dropped. Routes, skips and page conditions are not saved.
- Groups are listed under **Content → Form Builder → Field groups**. Opening one edits its name, description, fields and translations in the builder, with **Delete group** in the toolbar and **Used in** listing the forms that contain it.
- Permissions: **View Field Groups**, **Save and Edit Field Groups** and **Delete Field Groups**.

## Schedule

**Form settings → Schedule** sets **Opens at**, **Closes at**, a time zone and the messages shown outside that window. The open time is inclusive, the close time exclusive.

Outside the window the page shows only the message, and token, submit and draft saves are refused. Editing earlier answers is refused too; viewing, replying, exporting and deleting still work. Cron purges the cached pages the minute a time passes.

## Submission reference

Every submission gets a UUIDv7 reference, shown under the success message with a copy button (**Show the reference under the message**, on by default). It is left out when the message already uses `{{reference}}` for your own wording, and on forms that do not store submissions. It also appears in emails, the admin grid (searchable with or without hyphens), exports and My submissions. Silent spam rejections return a generated reference that is not stored.

## Newsletter field

Available only while `Magento_Newsletter` is enabled, and hidden in store views where newsletters are off. It is never required, never pre-ticked and never kept in a draft.

A ticked box subscribes a logged-in customer's account, or a guest's email (named by the field's **Email field** setting) when guest subscriptions are allowed in that store. Nothing is subscribed on edits or under Login as Customer.

## Translations

Choose a store view in the builder toolbar to translate every visitor-facing text: labels, help, options, messages, titles, buttons, the success message, SEO fields and the autoresponder. Blank translations use the default text, and a translation is flagged when the default changes.

The structure is shared: a new field appears in every store view. Answers are stored with the default labels.

Built-in texts ship for de_DE, fr_FR, es_ES, it_IT, nl_NL, pt_BR, pl_PL, sv_SE and da_DK. They are machine translations; corrections to `i18n/<locale>.csv` are welcome.

## SEO

Per store view: page title, meta description, a canonical link that ignores tracking parameters, hreflang between store views, `sitemap.xml` entries, and a "hide from search engines" option.

## Appearance

The stylesheet is scoped to `.ffb` and inherits the theme font. Per form, choose **Form builder styles** or **Theme styles only** (native controls for the theme to style), and an accent colour.

Themes can override the `--ffb-*` custom properties on `.ffb` (see `css/ffb.css`). My submissions and the reply pages use `.ffb-account` with the same properties.

## Moving forms

Duplicate a form from the grid or the builder; the copy opens disabled. Between environments, use the builder's JSON export and import, or `furanform:form:export` and `furanform:form:import` ([Commands](operations.md#commands)).

Translations and store view assignments travel with the file, keyed by store code. Importing a store code this install does not have is an error.
