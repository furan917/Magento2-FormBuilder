# Submissions and customers

## In the admin

**Content → Form Builder → Submissions** lists submissions with a summary, status and reference. The detail view groups answers by page and section, shows a customer panel, and offers file downloads.

- Statuses are new, in progress, answered and closed.
- The grid shows the newest 10,000 matches; use filters or Export for older ones. Select-all actions work through every match in batches.
- Mass actions: Delete and Change status.
- **Export** writes a CSV: Submission ID, Created At, Store ID, Customer ID, Reference, Status, one or more columns per field, then product columns when a submission has product context.
- Choice answers show their option labels in the grid summary, the detail view, My submissions, emails and the CSV.
- A retention period (Privacy settings) deletes old submissions hourly.

Permissions are split: viewing, replying and changing status, downloading files, exporting, deleting, analytics and privacy requests each have their own ACL resource.

## Replies

Admins reply from the submission. Replies support a small markdown subset (bold, italic, links, lists) with Ctrl/Cmd+B, I and K, and render the same in the admin, My submissions, the guest page and email. HTML is always shown as text.

- Each reply email has a **Reply on our website** button. All emails about one submission share `In-Reply-To` and `References` headers (Magento 2.4.8+ and Mage-OS).
- Set **Email → Reply-To for Admin Replies** to let customers answer the email directly. Without it the email says replies are not read.
- A customer reply reopens an answered submission and notifies the form's recipients.
- **Allow Customers to Reply** and the per-form **Customer replies** setting control who can reply; admins always can. **Maximum Messages per Conversation** caps a thread (200 by default).
- Long threads show the latest 100 messages with a link to earlier ones.
- Messages are cleaned before storing: control and invisible characters are removed (emoji sequences are kept), zalgo is capped, whitespace is collapsed, and the limit is 5,000 characters.

### Guest reply links

Guests reply through a signed link in the reply email, without an account.

- The link is HMAC-signed, valid 30 days, and bound to the submission, its store and the recipient's email. Closing, deleting, erasing or changing the email of the submission ends it, as does **Revoke reply links**.
- Opening it stores a 60-minute permission in the session and redirects to a URL without the token. Posting needs that session and the form key.
- The page shows the form title, date, status and conversation, never the answers or admin names. Invalid links all show the same 404.
- Pages are `no-store`, `noindex` and `no-referrer`. Limits: 30 link checks per IP per 10 minutes, 10 guest replies per submission per hour.
- The first request (`?t=...`) still appears in web server and CDN logs; restrict access to them or strip the `t` parameter.

## Customers

Per form, under **Form settings**:

- **Customer email field**: the answer that identifies a guest for claim links, dedupe, GDPR lookups and replies.
- **Prefill** from the customer account (name, email, phone, company, date of birth, gender, tax/VAT, addresses) or the product page. Prefill arrives with the form token. Locked fields are overwritten by the server on submit.
- **Access**: require login or limit to customer groups.
- **Survey mode**: `once` allows one answer per customer, `edit` also lets them change it while it is new or in progress. Optional guest dedupe by email is a soft limit: a repeat gets the normal thank-you but nothing is stored.

**My submissions** in the customer account lists, shows, edits (when allowed), deletes and exports a customer's submissions, with the conversation. Hidden fields and product context are not shown there.

When a customer logs in with guest submissions under the same email, they get a signed claim link (valid 7 days, one email per 24 hours) that links those submissions to their account.

Under Login as Customer, an admin sees prefill and previous answers but cannot submit, edit, reply, export or delete for the customer.

## Emails

- **Notification** to your recipients (with CC and BCC, and extra recipients by condition), carrying the answers.
- **Autoresponder** to the visitor, carrying only your text and the form title, never their answers. Its subject and body accept `{{reference}}`.
- Templates are chosen per store view under **Stores → Configuration → Furan → Form Builder → Email**. Edits do not send either email.
- Every email is built in the submission's store view: language, design, sender and links.
- Submission links in emails open the submission in the admin, after login when needed.

Templates receive `submission` (`id`, `created_at`, `status` label, `reference`), and the notification also gets `values` (a list of `label` and `value`) and `values_html`:

```
{{for item in values}}{{var item.label}}: {{var item.value}}{{/for}}
```

## Analytics

**Analytics** in the Forms grid row actions shows per-question charts: choice counts, rating averages and distributions, NPS, histograms, submissions over time, and the latest free-text answers (needs "View Submissions"; personal fields are excluded). Filter by store view, customer group and date range.

- Submits, edits and deletes write to a journal that cron folds into stat tables every minute. Analytics never reads the submissions table.
- How a question is bucketed is fixed when it is first answered. Number buckets come from the field's min and max (at most 50 buckets). Dates are bucketed by day when the min and max date rules span 92 days or less, otherwise by month.
- After changing a field's type under the same name, or its min or max rules, run `furanform:analytics:rebuild --form=ID --reset-specs`. After changing a store's time zone, run it without `--reset-specs` ([Commands](operations.md#commands)).

## Privacy

- **Personal data**: each field has a "contains personal data" flag, on by default for types such as email, phone, address and file. It decides what anonymise blanks and what analytics lists. Protection relies on the field declaration: a field not marked personal is treated as non-personal. Limit form editing to trusted roles.
- **GDPR export and erase**: **Content → Form Builder → Privacy**, by customer ID or email. Export is a zip with `data.json`, `data.csv` and the uploaded files. Each submission carries its status, and `data.json` also lists `webhook_recipients` and `missing_files`. Erase shows the matching count per form and asks for confirmation; by email it also covers accounts using that email.
- **Account deletion**: `anonymise` (default) blanks personal answers, files, IP and email fingerprint and keeps the rest; `keep` unlinks the submissions, and an account later registered with the same email can claim them through the claim email; `delete` erases them. With `anonymise` or `delete`, the account delete clears the email fingerprint of its submissions, so they cannot be claimed, and records them in the `furan_formbuilder_privacy_pending` table. The `furan_formbuilder.privacy` consumer processes them after the delete commits, and the `furan_formbuilder_privacy_pending` cron job every 5 minutes processes any it missed. Failed submissions are retried after 1 minute, 5 minutes, 30 minutes, 2 hours and then every 6 hours. After 10 failed attempts an admin notice is shown once a day.
- Files of erased or anonymised submissions are removed by cron within the hour.
- **Email fingerprints** are HMAC-SHA256 keyed from `crypt/key`. Key rotation uses `furanform:submissions:rehash` and `furanform:webhooks:reencrypt` ([Commands](operations.md#commands)).
- IP addresses are stored only when enabled on the form.
