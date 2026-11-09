# Webhooks

New, edited and re-statused submissions are posted to HTTPS endpoints you choose, signed with [Standard Webhooks](https://www.standardwebhooks.com/) v1 (HMAC-SHA256). Native CRM connectors are not included; a connector module can build on the event and transport in [Extending](extending.md).

## Setup

**Content → Form Builder → Webhooks → Add endpoint**:

- **URL**: https on port 443, resolving to a public address. Checked on save and again, with the address pinned, on every send. Redirects are not followed.
- **Events**: `submission.created`, `submission.updated` (survey or My submissions edit) and `submission.status_changed`. **Send test event** sends `webhook.test` with no submission data.
- **Scope and forms**: store views or websites, and chosen forms or all forms. A submission matches by the store view it was made in.
- **Fields to send**: all non-personal fields, or a list of field names.
- **Include personal data**: off by default (see [Privacy](#privacy)).
- **Keep a short excerpt of the receiver responses**: off by default; the first 1 KB, kept 7 days.
- **Signing secret** (`whsec_...`): shown once, never again. **Roll secret** keeps the old secret valid for up to 72 hours, signing with both meanwhile.

At most 50 active endpoints. Only forms that store submissions send webhooks.

| ACL resource | Allows |
| --- | --- |
| `webhooks` | View endpoints and the delivery log |
| `webhooks_manage` | Create, edit, roll the secret, delete, test. Also needs `submissions_export` |
| `webhooks_personal` | Turn personal data on, or change where a personal-data endpoint sends |
| `webhooks_replay` | Redeliver and resend |

Creating an endpoint, changing its URL and changing personal data are audited and raise an admin notice.

## Payload

One JSON object, at most 256 KB (larger fails as `payload_too_large`). It is rebuilt from the current data at send time, for retries and replays too.

```json
{
  "id": "msg_2f6a0d3c9b1e4f58a7d2c64e81b3a905",
  "type": "submission.created",
  "timestamp": "2026-11-08T10:15:30Z",
  "version": 1,
  "data": {
    "submission": {
      "id": 1042,
      "reference": "3f1c7a52-9e0b-4d7a-b2c1-6a8d0e5f4b13",
      "status": "new",
      "status_id": 0,
      "store_id": 1,
      "store_code": "default",
      "created_at": "2026-11-08T10:15:30Z",
      "updated_at": "2026-11-08T10:15:30Z"
    },
    "form": { "id": 7, "identifier": "contact", "title": "Contact us" },
    "fields": [
      { "name": "topic", "label": "Topic", "type": "select" },
      { "name": "message", "label": "Message", "type": "textarea" }
    ],
    "values": { "topic": "Order", "message": "Where is my parcel?" },
    "files": [],
    "product": null
  }
}
```

- `id` equals the `webhook-id` header. `timestamp` is when the event was queued and stays the same on retries.
- `status` is `new`, `in_progress`, `answered` or `closed`. `submission.status_changed` adds `data.change` with `old_status`, `new_status` and `source`.
- `values` carry the stored option value of choice answers, not the label.
- `customer_id` and `files` (`field`, `name`, `size`, `mime`; never contents or links) are sent only with personal data on.
- `product` is `{id, sku, name}` for forms opened from a product page.
- Hidden fields are sent like any other non-personal answer.
- IP address, email fingerprint, user agent and session data are never sent.

Requests are `POST`, `Content-Type: application/json`, with `webhook-id` (same on every retry), `webhook-timestamp` (fresh per attempt) and `webhook-signature` (space-separated `v1,<base64>`). The signature is HMAC-SHA256 of `webhook-id.webhook-timestamp.body`, keyed with the base64-decoded secret after `whsec_`.

## Delivery and retries

Answer with a 2xx within 10 seconds (3 to connect). The delivery is queued in the same transaction as the submission change, so a rollback sends nothing.

| Status | Meaning |
| --- | --- |
| Pending | Waiting for its first send or next retry |
| Sending | Claimed by a worker for 2 minutes |
| Delivered | The receiver answered 2xx |
| Failed | 8 attempts, 3 days, a 410, or an error a retry cannot fix |
| Blocked | The URL failed the safety checks at send time. Never retried |
| Cancelled | Endpoint deleted, switched off or no longer matching, submission gone, or vetoed by an observer |

- Every non-2xx, redirect, timeout and network error is retried after about 5 min, 30 min, 2 h, 5 h, 10 h, 14 h and 20 h, with jitter. `Retry-After` (up to 6 hours) can lengthen a delay, never shorten it.
- After 5 failures in a row an endpoint's queue waits for the next retry time. After 3 days of continuous failure, or one `410 Gone`, the endpoint is switched off with an admin notice.
- At most 2 deliveries per endpoint are in flight, and the drain takes turns between endpoints.
- Switching an endpoint off cancels its queue. Cancelled rows are not revived when it is switched on again; replay them from the log.
- Finished deliveries are kept for `furan_formbuilder/webhooks/retention_days` (30 days by default).

## Delivery log and replay

Each endpoint's **Delivery log** lists deliveries newest first, 50 per page, with every attempt (status code, error class and duration, never headers or bodies). Filter by status, or find a delivery by submission id, submission reference or the `webhook-id` your receiver logged. With `webhooks_replay`:

- **Redeliver** sends again with the same `webhook-id`.
- **Resend** sends with a new `webhook-id`.

Both rebuild the payload from current data and re-check the URL. Limit: 30 replays per admin per 10 minutes.

## Privacy

- Personal fields and `customer_id` are left out unless **Include personal data** is on, which needs `webhooks_personal`.
- A field counts as personal when it is ticked "Contains personal data", its type is personal by default (email, phone, address, map, file), its autocomplete is a personal one, or it is no longer in the form. A field changed away from a personal type stays personal for answers stored under the old type. Unticking does not make such a field non-personal for webhooks.
- Plain text fields, such as a name typed into a text box, are sent unless ticked, so tick them. Privacy export and erasure follow the tick alone.
- An endpoint is a recipient of your customers' data. Erase, anonymise and retention remove delivery rows only; Form Builder cannot recall data already delivered. The privacy export lists, by name and host, every endpoint that answered or may have received the body (`webhook_recipients`).

## Settings in env.php

URL rules live only in `app/etc/env.php`. An invalid value falls back to the secure default and logs a warning.

```php
'furan_formbuilder' => [
    'webhooks' => [
        'enabled' => true,
        'allow_http' => false,
        'ports' => [443],
        'host_allowlist' => ['*.example.com', 'hooks.example.org'],
        'ip_literal_hosts' => [],
        'nat64_decode' => false,
        'allow_cidrs' => [],
        'connect_timeout_ms' => 3000,
        'timeout_ms' => 10000,
    ],
    'map' => [
        'ports' => [443],
    ],
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `true` | Kill switch. Only `false` turns it off: nothing is queued, claimed or sent |
| `allow_http` | `false` | Allow `http://` URLs. Development only |
| `ports` | `[443]` | Ports a webhook URL may use |
| `host_allowlist` | `[]` | Exact hosts or `*.suffix`; empty allows any public host |
| `ip_literal_hosts` | `[]` | IP literals a URL may use; others are rejected |
| `nat64_decode` | `false` | Judge the IPv4 inside `64:ff9b::/96` instead of blocking it |
| `allow_cidrs` | `[]` | Private ranges treated as public. Development only |
| `connect_timeout_ms` | `3000` | 1000 to 30000 |
| `timeout_ms` | `10000` | Per request, 5000 to 30000 |

`allow_http` and `allow_cidrs` work only in developer mode; they are ignored in production mode and logged. `furan_formbuilder/map/ports` adds ports for the map address search, which uses the same client.

## Operator notes

- **Consumer or cron.** New deliveries wake the `furan_formbuilder.webhook` consumer. Without it, the `furan_formbuilder_webhook_drain` job (cron group `furan_formbuilder_realtime`, every minute, up to 200 sends) sends them. Retries always run from cron. The hourly purge, `furan_formbuilder_webhook_retention`, runs in the housekeeping group `furan_formbuilder`.
- **Restart consumers** after changing webhook settings in `env.php`.
- **Delivery is at least once and unordered.** Deduplicate on `webhook-id` and order by `updated_at` or the status in the body.
- **Egress controls.** Every address is checked and pinned, proxies are ignored and redirects refused. That cannot stop the TCP connect and TLS ClientHello reaching the pinned address, internal services on public addresses, or split-horizon DNS. Run cron and consumers behind an egress firewall that denies private, link-local and metadata ranges, or a filtering proxy such as smokescreen. On AWS require IMDSv2 with a hop limit of 2 for containers.
- **DNS.** PHP has no per-lookup timeout; bound it in `/etc/resolv.conf` with `options timeout:1 attempts:2`. Resolved addresses are cached for 60 seconds, failures for 10.
- **Secrets** are encrypted with `crypt/key`; key rotation uses `furanform:webhooks:reencrypt` ([Commands](operations.md#commands)). A secret that cannot be decrypted fails the delivery and switches the endpoint off.
- Observers of `furan_formbuilder_webhook_send_before` receive the full endpoint URL. Treat it as a secret.

## Verifying a webhook

Verify against the raw body, compare in constant-time, reject timestamps more than 300 seconds off, accept any `v1,` signature in the header (two during a secret roll), and skip a `webhook-id` you have already processed. The official Standard Webhooks libraries do the same.

PHP (`$headers` with lower-case names):

```php
function verifyWebhook(string $secret, array $headers, string $rawBody, ?int $now = null): bool
{
    $id = $headers['webhook-id'] ?? '';
    $timestamp = $headers['webhook-timestamp'] ?? '';
    $signatures = $headers['webhook-signature'] ?? '';
    if ($id === '' || !ctype_digit($timestamp) || abs(($now ?? time()) - (int)$timestamp) > 300) {
        return false;
    }
    $key = strncmp($secret, 'whsec_', 6) === 0 ? base64_decode(substr($secret, 6), true) : false;
    if ($key === false || $key === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $rawBody, $key, true));
    $valid = false;
    foreach (explode(' ', $signatures) as $candidate) {
        if (str_starts_with($candidate, 'v1,') && hash_equals($expected, substr($candidate, 3))) {
            $valid = true;
        }
    }
    return $valid;
}

// verifyWebhook($secret, array_change_key_case(getallheaders()), file_get_contents('php://input'))
```

Node (with Express, read the body with `express.raw({ type: 'application/json' })`):

```js
const crypto = require('node:crypto');

function verifyWebhook(secret, headers, rawBody, now = Date.now() / 1000) {
  const id = headers['webhook-id'];
  const timestamp = headers['webhook-timestamp'];
  const signatures = headers['webhook-signature'] || '';
  if (!id || !/^\d+$/.test(timestamp || '') || Math.abs(now - Number(timestamp)) > 300) return false;
  if (!secret.startsWith('whsec_')) return false;
  const key = Buffer.from(secret.slice(6), 'base64');
  const expected = crypto.createHmac('sha256', key).update(`${id}.${timestamp}.`).update(rawBody).digest();
  let valid = false;
  for (const candidate of signatures.split(' ')) {
    if (!candidate.startsWith('v1,')) continue;
    const given = Buffer.from(candidate.slice(3), 'base64');
    if (given.length === expected.length && crypto.timingSafeEqual(given, expected)) valid = true;
  }
  return valid;
}
```

Store the event, answer 2xx, then do the work.
