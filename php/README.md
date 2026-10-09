# MailFlash PHP client

Single-file PHP client for the [MailFlash](https://mailflash.es) transactional email API. No framework required — works in any PHP app.

> **Laravel users:** use [`laravel/client/`](../laravel/client/) instead — it wraps the Illuminate HTTP client for better testability and DI.

## Requirements

- PHP 8.1+
- `ext-curl` (enabled by default in most PHP installs)

## Installation

Copy [`MailFlashClient.php`](./MailFlashClient.php) into your project.

### Option A — `require_once`

```php
require_once __DIR__ . '/MailFlashClient.php';

$client = new \MailFlash\Client\MailFlashClient(
    'https://mailflash.es',
    $_ENV['MAILFLASH_API_KEY']
);
```

### Option B — Composer classmap

Add to `composer.json`:

```json
{
    "autoload": {
        "classmap": ["path/to/MailFlashClient.php"]
    }
}
```

Then run `composer dump-autoload` and use the class normally.

## Usage

```php
$result = $client->send([
    'from'    => 'hello@yourdomain.com',
    'to'      => ['you@example.com'],
    'subject' => 'Order confirmed',
    'html'    => '<p>Thanks for your order!</p>',
], 'order-123');

if ($client->accepted($result)) {
    // $result['body']['id']     — email UUID
    // $result['body']['status'] — 'queued'
} else {
    error_log('MailFlash failed: ' . $result['status'] . ' ' . json_encode($result['body']));
}
```

### Named recipients

```php
$result = $client->send([
    'from' => 'hello@yourdomain.com',
    'to'   => [
        ['email' => 'alice@example.com', 'name' => 'Alice'],
        'bob@example.com',
    ],
    'subject' => 'Hello',
    'html'    => '<p>Hi!</p>',
]);
```

### Attachments (base64-encoded)

Up to 10 files, 10 MB in total (decoded). For an inline image, set `content_id` (it must contain `@`, e.g. `logo@yourdomain.com`) and reference it from the HTML as `cid:logo@yourdomain.com`.

```php
$result = $client->send([
    'from'    => 'billing@yourdomain.com',
    'to'      => ['customer@example.com'],
    'subject' => 'Your invoice',
    'html'    => '<p>Please find your invoice attached.</p>',
    'attachments' => [[
        'filename'     => 'invoice.pdf',
        'content'      => base64_encode(file_get_contents('invoice.pdf')),
        'content_type' => 'application/pdf',
    ]],
]);
```

### CC / BCC

```php
$result = $client->send([
    'from'    => 'hello@yourdomain.com',
    'to'      => ['alice@example.com'],
    'cc'      => ['manager@example.com'],
    'bcc'     => ['archive@yourcompany.com'],
    'subject' => 'Quarterly report',
    'html'    => '<p>See attached.</p>',
]);
```

## API

### `new MailFlashClient(string $baseUrl, string $apiKey, int $timeoutSeconds = 30)`

### `send(array $payload, ?string $idempotencyKey = null): array`

Returns `['status' => int, 'body' => array|string]`.

A `status` of `202` means accepted. `status=0` means a transport error (`body.error` is `transport`) or a payload that could not be JSON-encoded (`body.error` is `encode`, e.g. invalid UTF-8).

### `accepted(array $result): bool`

Returns `true` if `$result['status'] === 202`.

### `ok(array $result): bool`

Returns `true` for any 2xx status.

### Read endpoints

All return `['status' => int, 'body' => array|string]`.

```php
$client->getStats('2026-06-01', '2026-06-30');
$client->listEmails(['status' => 'delivered', 'tag' => 'orders', 'page' => 2]);
$client->getEmail('uuid-here', includeBody: true);
$client->getEmailEvents('uuid-here');
$client->listContacts(['status' => 'suppressed']);
$client->listDomains(verifiedOnly: true);
```
