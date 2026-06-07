# MailFlash SDKs

Drop-in client libraries for the [MailFlash](https://mailflash.es) transactional email API.

Each SDK is a **single file** — no build step, no package manager required. Copy it into your project and you're done.

---

## Available clients

| Language | Directory | Requires |
|---|---|---|
| Python 3.10+ | [`python/`](./python/) | `pip install requests` |
| Node.js 18+ | [`node/`](./node/) | nothing (native `fetch`) |
| PHP 8.1+ | [`php/`](./php/) | `ext-curl` (standard) |
| Laravel 10+ | [`laravel/`](./laravel/) | mail driver + API client — see [`laravel/README.md`](./laravel/README.md) |
| WordPress 5.7+ | [`wordpress/`](./wordpress/) | PHP 8.0+, `ext-curl` |

---

## Quick start

### Python

```python
# 1. Copy python/mailflash_client.py into your project
from mailflash_client import MailFlashClient

client = MailFlashClient('https://mailflash.es', 'YOUR_API_KEY')

result = client.send({
    'from': 'hello@yourdomain.com',
    'to': ['you@example.com'],
    'subject': 'Order confirmed',
    'html': '<p>Thanks for your order!</p>',
}, idempotency_key='order-123')

if client.accepted(result):
    print(result['body'])
```

### Node.js / TypeScript

```ts
// 1. Copy node/mailflash_client.ts into your project
import { MailFlashClient } from './mailflash_client.js';

const client = new MailFlashClient('https://mailflash.es', process.env.MAILFLASH_API_KEY!);

const { status, body } = await client.send({
    from: 'hello@yourdomain.com',
    to: ['you@example.com'],
    subject: 'Order confirmed',
    html: '<p>Thanks for your order!</p>',
}, { idempotencyKey: 'order-123' });
```

### PHP

```php
// 1. Copy php/MailFlashClient.php into your project
require_once 'MailFlashClient.php';

$client = new \MailFlash\Client\MailFlashClient(
    'https://mailflash.es',
    $_ENV['MAILFLASH_API_KEY']
);

$result = $client->send([
    'from'    => 'hello@yourdomain.com',
    'to'      => ['you@example.com'],
    'subject' => 'Order confirmed',
    'html'    => '<p>Thanks for your order!</p>',
], 'order-123');

if ($client->accepted($result)) {
    var_dump($result['body']);
}
```

### Laravel

See [`laravel/README.md`](./laravel/README.md) for both integrations.

**Mail driver** (recommended for most apps):

```env
MAIL_MAILER=mailflash
MAIL_FROM_ADDRESS=hello@yourdomain.com
MAILFLASH_API_KEY=your_project_key_here
```

```php
// Copy laravel/driver/* to app/Mail/MailFlash/ — see laravel/driver/README.md
Mail::to($user)->send(new WelcomeMail());
```

**API client** (direct `$mailflash->send([...])` calls):

```php
// Copy laravel/client/MailFlashClient.php — see laravel/client/README.md
app(\MailFlash\Client\MailFlashClient::class)->send([
    'from'    => 'hello@yourdomain.com',
    'to'      => ['you@example.com'],
    'subject' => 'Order confirmed',
    'html'    => '<p>Thanks for your order!</p>',
], 'order-123');
```

### WordPress

```text
1. Upload wordpress/mailflash-wp.zip via Plugins → Add New → Upload Plugin
2. Activate MailFlash
3. Settings → MailFlash → paste your API key and from address
4. Send a test email from the settings page
```

See [`wordpress/README.md`](./wordpress/README.md) for full setup and troubleshooting.

---

## Authentication

Every request requires an `X-API-Key` header. Get your key from the MailFlash dashboard under **Projects**.

```
X-API-Key: YOUR_PROJECT_API_KEY
```

---

## Idempotency

Pass an `idempotency_key` (Python / PHP) or `idempotencyKey` (Node) to safely retry failed requests. Duplicate requests with the same key within 24 hours return the original response without re-sending.

---

## Send payload reference

| Field | Type | Required | Notes |
|---|---|---|---|
| `from` | string | yes | Sender address — must be on a verified domain |
| `to` | array | yes | Recipients: `"email@example.com"` or `{"email": "...", "name": "..."}` |
| `subject` | string | yes | |
| `from_name` | string | | Display name for the sender |
| `cc` | array | | Same format as `to` |
| `bcc` | array | | Same format as `to` |
| `reply_to` | string | | |
| `html` | string | | HTML body (one of `html` or `text` required) |
| `text` | string | | Plain-text body |
| `headers` | object | | Extra SMTP headers |
| `attachments` | array | | `{filename, content (base64), content_type?}` |
| `tags` | array | | String tags for filtering in the dashboard |
| `track_opens` | bool | | Default: project setting |
| `track_clicks` | bool | | Default: project setting |

---

## Response codes

| Status | Meaning |
|---|---|
| `202` | Accepted — email queued successfully |
| `401` | Missing or invalid API key |
| `403` | Sending domain not verified |
| `422` | Validation error or all recipients suppressed |
| `429` | Rate limit exceeded |

---

## Resources

- [MailFlash dashboard](https://mailflash.es)
- [API reference](https://mailflash.es/docs/api)
- [Full documentation](https://mailflash.es/docs)
