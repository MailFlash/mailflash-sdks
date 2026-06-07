# MailFlash Node.js / TypeScript client

Single-file TypeScript client for the [MailFlash](https://mailflash.es) transactional email API.

## Requirements

- Node.js 18+ (uses native `fetch` — no dependencies)
- TypeScript optional but supported

## Installation

Copy [`mailflash_client.ts`](./mailflash_client.ts) into your project. No `npm install` needed.

For plain JavaScript, compile it once with `tsc` or use `ts-node`/`tsx` directly.

## Usage

```ts
import { MailFlashClient } from './mailflash_client.js';

const client = new MailFlashClient(
    'https://mailflash.es',
    process.env.MAILFLASH_API_KEY!,
);

const result = await client.send({
    from: 'hello@yourdomain.com',
    to: ['you@example.com'],
    subject: 'Order confirmed',
    html: '<p>Thanks for your order!</p>',
}, { idempotencyKey: 'order-123' });

if (client.accepted(result)) {
    console.log(result.body); // { id: '...', status: 'queued' }
} else {
    console.error('Failed:', result.status, result.body);
}
```

### Named recipients

```ts
await client.send({
    from: 'hello@yourdomain.com',
    to: [
        { email: 'alice@example.com', name: 'Alice' },
        'bob@example.com',
    ],
    subject: 'Hello',
    html: '<p>Hi!</p>',
});
```

### Attachments

```ts
import { readFileSync } from 'fs';

await client.send({
    from: 'billing@yourdomain.com',
    to: ['customer@example.com'],
    subject: 'Your invoice',
    html: '<p>Please find your invoice attached.</p>',
    attachments: [{
        filename: 'invoice.pdf',
        content: readFileSync('invoice.pdf').toString('base64'),
        content_type: 'application/pdf',
    }],
});
```

### Cancel a request

```ts
const controller = new AbortController();
setTimeout(() => controller.abort(), 5_000);

const result = await client.send({ ... }, { signal: controller.signal });
```

### Other API methods

```ts
// Stats for the project
const stats = await client.getStats({ dateFrom: '2026-06-01' });

// List sent emails
const emails = await client.listEmails({ status: 'delivered', page: 2 });

// Get a single email with its HTML/text body
const email = await client.getEmail('uuid-here', { includeBody: true });

// Get delivery/open/click events for an email
const events = await client.getEmailEvents('uuid-here');

// List suppressed contacts
const contacts = await client.listContacts({ status: 'suppressed' });

// List verified sending domains
const domains = await client.listDomains({ verifiedOnly: true });
```

## API

### `new MailFlashClient(baseUrl, apiKey, timeoutMs?)`

| Param | Type | Description |
|---|---|---|
| `baseUrl` | `string` | MailFlash base URL, e.g. `https://mailflash.es` |
| `apiKey` | `string` | Your project API key |
| `timeoutMs` | `number` | Request timeout in ms (default `30_000`) |

### `client.send(payload, options?) → Promise<ApiResult>`

Sends an email. Returns `{ status: number, body: object | string }`.

### `client.accepted(result) → boolean`

Returns `true` if `result.status === 202`.

### `client.ok(result) → boolean`

Returns `true` for any 2xx status.
