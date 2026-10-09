# MailFlash Laravel mail driver

Drop-in Laravel mail transport for the [MailFlash](https://mailflash.es) transactional email API.

Set `MAIL_MAILER=mailflash` and use Laravel's normal mail API — `Mail::to()`, Mailable classes, notifications — with no MailFlash-specific code in your app.

> **Need direct API calls instead?** Use [`client/`](../client/) — an HTTP client for `$mailflash->send([...])`. This driver is for apps that want standard Laravel mail.

---

## Requirements

- PHP 8.1+
- Laravel 10+ (uses Laravel's HTTP client, which ships with the default Laravel install)

---

## Mail driver vs API client

| | Mail driver ([`driver/`](./)) | API client ([`client/`](../client/)) |
|---|---|---|
| Usage | `Mail::to($user)->send(new WelcomeMail())` | `$mailflash->send(['from' => ..., 'to' => ...])` |
| Config | `MAIL_MAILER=mailflash` | Manual DI / service container |
| Best for | Existing Laravel mail code, Mailables, notifications | Custom send logic, non-mail flows |

---

## Installation

### 1. Copy the files

Copy both files into your Laravel app:

```
app/Mail/MailFlash/MailFlashTransport.php
app/Mail/MailFlash/MailFlashServiceProvider.php
```

Keep the `App\Mail\MailFlash` namespace as-is.

### 2. Register the service provider

**Laravel 11+** — add to `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Mail\MailFlash\MailFlashServiceProvider::class,
];
```

**Laravel 10** — add to `config/app.php`:

```php
'providers' => [
    // ...
    App\Mail\MailFlash\MailFlashServiceProvider::class,
],
```

### 3. Add the mailer — `config/mail.php`

Add a `mailflash` entry to the `mailers` array:

```php
'mailers' => [
    // ...

    'mailflash' => [
        'transport' => 'mailflash',
    ],
],
```

### 4. Add API key config — `config/services.php`

```php
'mailflash' => [
    'key' => env('MAILFLASH_API_KEY'),
],
```

### 5. Set `.env`

```env
MAIL_MAILER=mailflash
MAIL_FROM_ADDRESS=hello@yourdomain.com
MAIL_FROM_NAME="Your App"
MAILFLASH_API_KEY=your_project_key_here
```

The **from address** must be on a domain verified in your MailFlash project (SPF/DKIM/DMARC).

The driver connects to `https://mailflash.es` by default. To point it elsewhere (e.g. staging), add `'url' => env('MAILFLASH_URL', 'https://mailflash.es')` to `services.mailflash` and set `MAILFLASH_URL` — the same key the [API client](../client/) uses.

Get your API key from the MailFlash dashboard under **Projects → API key**.

---

## Usage

Once configured, send mail the Laravel way — no MailFlash imports required.

### Mailable class

```php
use App\Mail\OrderConfirmed;
use Illuminate\Support\Facades\Mail;

Mail::to($order->customer_email)->send(new OrderConfirmed($order));
```

### Inline

```php
Mail::raw('Your password reset link is ...', function ($message) use ($user) {
    $message->to($user->email)->subject('Reset your password');
});
```

### With attachments

Attachments from Mailables and `$message->attach()` are sent to MailFlash as base64-encoded files (up to 10 files, 10 MB in total).

```php
Mail::send('emails.invoice', ['invoice' => $invoice], function ($message) use ($invoice) {
    $message->to($invoice->customer_email)
        ->subject('Your invoice')
        ->attach(storage_path('invoices/'.$invoice->pdf_path));
});
```

### Inline images

`$message->embed()` / `embedData()` work as usual — in Blade, `<img src="{{ $message->embed($pathToImage) }}">`. The driver sends each embedded image with its Content-ID and rewrites the `cid:` reference to match.

### Notifications

Laravel notifications that use the `mail` channel work automatically when `MAIL_MAILER=mailflash`.

---

## Testing

Use Laravel's built-in mail fake — the MailFlash transport is never called:

```php
use Illuminate\Support\Facades\Mail;

Mail::fake();

// ... run code that sends mail ...

Mail::assertSent(OrderConfirmed::class);
```

To test the transport itself (the actual API payload), fake the HTTP layer instead — the driver uses Laravel's HTTP client:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'mailflash.es/*' => Http::response(['id' => 'test-uuid', 'status' => 'queued'], 202),
]);

Mail::to('you@example.com')->send(new OrderConfirmed($order));

Http::assertSent(fn ($request) => $request['subject'] === 'Order confirmed');
```

---

## Tracking

Open and click tracking follow your **project defaults** in the MailFlash dashboard. The mail driver does not expose per-email `track_opens` / `track_clicks` overrides. Use the [`client`](../client/) if you need per-message control.

---

## Field mapping

| Laravel / Symfony MIME | MailFlash API |
|---|---|
| From address + name | `from` + `from_name` |
| To / Cc / Bcc | `to` / `cc` / `bcc` |
| Subject | `subject` |
| HTML body | `html` |
| Text body | `text` |
| Reply-To (first address) | `reply_to` |
| Attachments, embedded images | `attachments` (base64; embedded images carry `content_id` + `disposition: inline`) |
| Mailable tags (`Envelope(tags: [...])`, `->tag()`) | `tags` |
| Custom headers (`X-*`, `List-Unsubscribe`, Mailable metadata as `X-Metadata-*`) | `headers` (applied to the sent message; MailFlash keeps its own From, To, Subject, Message-ID and List-Unsubscribe) |

---

## Troubleshooting

### `MailFlash API key is not configured`

Set `MAILFLASH_API_KEY` in `.env` and run `php artisan config:clear`.

### HTTP 401 — invalid API key

Copy the key again from the MailFlash dashboard (Projects → API key).

### HTTP 403 — domain not verified

`MAIL_FROM_ADDRESS` must use a domain verified in your MailFlash project. Complete SPF/DKIM/DMARC in the dashboard.

### HTTP 422 — validation or suppression

- Check the recipient is not on your project's suppression list.
- Ensure at least one of `html` or `text` is present (Laravel usually provides both).

### HTTP 429 — rate limited

Your project hit its per-minute send limit. Retry after the backoff period.

### Email accepted (202) but not received

- Check spam folders.
- Confirm DNS records show verified in MailFlash.
- Open the email in the MailFlash dashboard for delivery status.

---

## Resources

- [MailFlash dashboard](https://mailflash.es)
- [API reference](https://mailflash.es/docs/api)
- [Other MailFlash SDKs](../../README.md)
