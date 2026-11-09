# Operations

## Configuration

**Stores → Configuration → Furan → Form Builder**:

| Group | Settings |
| --- | --- |
| General | **Enable Forms**, URL prefix, **Run Submission Actions in the Background** |
| Spam Protection | Minimum seconds before submit, per-visitor and per-form rate limits, rate window, **Trusted Proxy IPs** |
| File Uploads | Maximum size, allowed extensions, upload budget per form per hour |
| Privacy | Submission retention, browser draft lifetime, what happens on customer account deletion |
| Email | Sender, templates per email, background claim and reply emails, **Reply-To for Admin Replies** |
| Maps | Provider (OpenStreetMap or Google), keys, tiles, address search |
| Customers | Claim links, server drafts, customer and guest replies, maximum messages per conversation |
| Analytics | Show the analytics page |
| Webhooks | Delivery retention |

**URL Prefix** (default `forms`, global) starts with a lowercase letter or digit, then lowercase letters, digits and hyphens, at most 32 characters. A name used by a storefront route (for example `checkout`, `customer`, `contact`) is refused. Saving it flushes the page and block caches. A CMS page at the same path wins over an unknown form identifier.

**Enable Forms** is the emergency switch. Setting it to No takes every form in that scope offline at once (pages, widgets, submit, token, upload, draft and map endpoints, My submissions, guest reply and claim pages) and flushes the page caches, Varnish included. Admin pages and stored submissions are unaffected.

```
bin/magento config:set furan_formbuilder/general/enabled 0 [--scope=websites --scope-code=<code>]
```

`doc` and `xls` uploads are not accepted.

## Cron and queues

Magento cron must run: it removes files of deleted submissions and abandoned uploads, applies retention, removes expired drafts and rate-limit counters, finishes large form deletes, retries failed submission actions, purges cached pages when a form's open or close time passes (`furan_formbuilder_form_schedule`, every minute), folds analytics, and runs queued analytics rebuilds (group `furan_formbuilder_stats`, 02:30).

| Consumer | Needed when |
| --- | --- |
| `furan_formbuilder.webhook` | Webhooks are used. Cron drains deliveries as a fallback |
| `furan_formbuilder.action` | **Run Submission Actions in the Background** is on |
| `furan_formbuilder.mail` | Background claim and reply emails are on |
| `furan_formbuilder.export` | A CSV export has more than 20,000 submissions |
| `furan_formbuilder.privacy` | **When a Customer Account Is Deleted** is anonymise or delete. Cron processes deleted accounts as a fallback |

If `env.php` has a `cron_consumers_runner/consumers` allowlist, add the ones you use.

| Cron group | Jobs |
| --- | --- |
| `furan_formbuilder` | Form purge, file cleanup, retention, counters, webhook retention, drafts, deleted account privacy work |
| `furan_formbuilder_realtime` | Form schedule, action retries, webhook drain |
| `furan_formbuilder_fold` | Analytics fold |
| `furan_formbuilder_stats` | Analytics rebuilds |

Each group runs in its own process.

## Table growth

| Table (`furan_formbuilder_` prefix) | Grows with | Bounded by |
| --- | --- | --- |
| `submission` | Submissions | **Privacy → Submission retention** (off by default) |
| `message`, `file`, `webhook_recipient` | Submissions | Deleted with their submission |
| `webhook_delivery`, `webhook_attempt` | Webhook sends | `furan_formbuilder/webhooks/retention_days` (30 days) |
| `stat_journal` | Submits, edits, deletes | Folded and deleted every minute |
| `counter`, `draft` | Visitors | Expiry cleanup, hourly and daily |
| `privacy_pending` | Deleted customer accounts | Removed once each submission is erased or anonymised |
| `stat_*` | Answer values and days | Not submissions |

Single-row submission inserts measured on MySQL 8 with a 1 GB buffer pool and the change buffer off:

| Rows | p50 | p99 |
| --- | --- | --- |
| 1M | 7.2 ms | 24.6 ms |
| 32M | 7.8 ms | 26.8 ms |

Set a submission retention period on stores that collect millions of submissions a year.

## Commands

```
bin/magento furanform:form:export <identifier|id> [--output=file]
bin/magento furanform:form:import <file> [--update]
bin/magento furanform:analytics:rebuild [--form=ID] [--reset-specs]
bin/magento furanform:submissions:rehash [--batch-size=500] [--form-id=ID]
bin/magento furanform:webhooks:reencrypt [--batch-size=200]
```

- `analytics:rebuild` recomputes analytics from submissions. `--reset-specs` re-reads how each question is bucketed; use it after changing a field's type under the same name, or its min or max rules. It reads in one long snapshot, so run it off-peak. Memory stays flat (about 80 MB); 1M submissions take about 4 minutes.
- `submissions:rehash` and `webhooks:reencrypt` move email fingerprints and webhook secrets to the newest `crypt/key`. Never reorder keys in `env.php`. After adding a key, run both before removing the old one.

## reCAPTCHA

Enable it under **Stores → Configuration → Security → Google reCAPTCHA Storefront → Enable for Form Builder Forms** (v2 checkbox, v2 invisible or v3). It uses Magento's reCAPTCHA modules and keys, and applies to every form on the website.

- Google's script loads on the visitor's first interaction with the form.
- If Google cannot be reached, the submission is refused. If the chosen type's module is disabled, submissions are accepted with a logged warning and an admin notice.
- Saving the section in the admin purges cached form pages. After `config:set` or editing `env.php`, run `bin/magento cache:clean full_page`.

## Maps

The map field stores a pin and loads only when the visitor clicks **Load map**.

- **OpenStreetMap** (default) uses bundled Leaflet. Use your own tile server for busy sites.
- **Address search** (OSM only, off by default) goes through the store to Nominatim or your endpoint, limited to one request per second site-wide and 20 per visitor per 10 minutes. The endpoint must resolve to a public address.
- Results are cached for 7 days in the optional `furan_formbuilder_geo` cache type (**Form Builder Address Search** in Cache Management). It is disabled when the module is added to an existing store; enable it with `bin/magento cache:enable furan_formbuilder_geo`. Without it every search goes to the endpoint.
- **Google Maps** needs an API key and a Map ID. The key is public in the page, so restrict it by referrer and API. Google Maps needs `'unsafe-eval'` and `'unsafe-inline'` in the storefront CSP; the module adds Google's hosts but never those keywords.

## Store views and URLs

Token, submit, draft and login URLs, email links and redirects carry the store view they belong to. Settings resolve against the submission's own store.

- On multi-website installs without `MAGE_RUN_TYPE=website`, `___store` can select any active store view. Assign forms to specific store views when that matters.
- A form only submits from the store view's configured base URL host.

## Proxies, Varnish and multiple servers

- **Visitor IP**: rate limits and stored IPs use `REMOTE_ADDR` and ignore `X-Forwarded-For` unless the request comes from a **Trusted Proxy IPs** address. IPv6 visitors share one limit per /64. reCAPTCHA uses Magento's `RemoteAddress`, so configure that for your proxies too.
- **Rate limits** are counted in the database and hold across web nodes and cache flushes.
- **Uploads** live in `var/furan_formbuilder` through the `import_export` directory, which remote storage (S3) maps. Without remote storage, share `var/` between nodes. Files are only served through the admin download.
- **Cache**: form pages are full-page cached and purged on save. Visitor data (prefill, previous answers, access) comes from the uncached token request.

## Security

- The document root must be `pub/`. On Apache keep `var/.htaccess`. A remote storage bucket must be private.
- Real upload limits are the server's (`post_max_size`, `upload_max_filesize`, `client_max_body_size`). A smaller `client_max_body_size` on `/furanform/form/submit/` stops large bodies earlier.
- Uploads are checked by extension, MIME type and a scan for embedded code. That is not a virus scanner; scan uploads where you can and enable reCAPTCHA on forms with file fields.
- Visitors only trigger emails you wrote. The autoresponder never carries answers and is capped at 1 per address per form per day, 3 per address per day, 50 per form per hour, and a daily limit per store view (Email → Confirmation Emails per Day, default 500).
- Storefront scripts only call the page's own origin and load from the module's static files and Google's reCAPTCHA and Maps hosts.
- Apply Adobe's security patches for your Magento version.
- On Adobe Commerce, admin role website restrictions are not applied to forms or submissions.

## Known limits

- Submitting needs JavaScript; without it the form shows a notice.
- A form widget in a CMS block cached through `block_html` is not purged when the form changes. CMS pages and Page Builder content are fine.
- Do not set `cache_lifetime` on a parent block of a form; it freezes the product context.
- Product context comes from the `ffb_product_id` the page sends. Any enabled, visible product in the website is accepted, not only the one on the page.
- Postcodes that do not match the country's format show a hint but are accepted.
