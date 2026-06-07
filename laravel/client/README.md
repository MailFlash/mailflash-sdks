# MailFlash PHP/Laravel client

Single-file Laravel client for the [MailFlash](https://mailflash.es) transactional email API. Uses the Illuminate HTTP client instead of raw cURL — full support for fakes, retries, and DI.

> **Want standard Laravel mail instead?** Use the [`driver/`](../driver/) mail transport — set `MAIL_MAILER=mailflash` and use `Mail::to()->send()` with no MailFlash code in your app.

> **Not using Laravel?** Use [`php/`](../../php/) instead — it only requires `ext-curl`.

## Requirements

- PHP 8.1+
- Laravel 10+

## Installation

### 1. Copy the file

Copy [`MailFlashClient.php`](./MailFlashClient.php) into your app:

```
app/Services/MailFlash/MailFlashClient.php
```

### 2. Add config

In `config/services.php`:

```php
'mailflash' => [
    'url' => env('MAILFLASH_URL', 'https://mailflash.es'),
    'key' => env('MAILFLASH_API_KEY'),
],
```

In `.env`:

```
MAILFLASH_URL=https://mailflash.es
MAILFLASH_API_KEY=your_project_key_here
```

### 3. Register the singleton

In `app/Providers/AppServiceProvider.php`:

```php
use MailFlash\Client\MailFlashClient;

public function register(): void
{
    $this->app->singleton(MailFlashClient::class, function () {
        return new MailFlashClient(
            config('services.mailflash.url'),
            config('services.mailflash.key'),
        );
    });
}
```

## Usage

### Inject via constructor (recommended)

```php
use MailFlash\Client\MailFlashClient;

class OrderController extends Controller
{
    public function __construct(private readonly MailFlashClient $mailflash) {}

    public function confirm(Order $order): JsonResponse
    {
        $result = $this->mailflash->send([
            'from'    => 'orders@yourdomain.com',
            'to'      => [$order->customer_email],
            'subject' => 'Order #'.$order->id.' confirmed',
            'html'    => view('emails.order-confirmed', ['order' => $order])->render(),
        ], idempotencyKey: 'order-'.$order->id);

        $this->mailflash->throwIfFailed($result);

        return response()->json(['sent' => true]);
    }
}
```

### Resolve from the container

```php
app(\MailFlash\Client\MailFlashClient::class)->send([...], 'order-1');
```

### Named recipients

```php
$result = $mailflash->send([
    'from' => 'hello@yourdomain.com',
    'to'   => [
        ['email' => 'alice@example.com', 'name' => 'Alice'],
        'bob@example.com',
    ],
    'subject' => 'Hello',
    'html'    => '<p>Hi!</p>',
]);
```

### Throw on failure

```php
$result = $mailflash->send([...]);
$mailflash->throwIfFailed($result); // throws Illuminate\Http\Client\RequestException on non-202
```

### Check manually

```php
$result = $mailflash->send([...]);

if ($mailflash->accepted($result)) {
    logger()->info('Email queued', ['id' => $result['body']['id']]);
} else {
    logger()->error('MailFlash error', ['status' => $result['status'], 'body' => $result['body']]);
}
```

### Faking in tests

Because the client uses `Illuminate\Support\Facades\Http` internally, you can use Laravel's built-in HTTP fake:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'mailflash.es/*' => Http::response(['id' => 'test-uuid', 'status' => 'queued'], 202),
]);

$result = $mailflash->send([...]);
$this->assertTrue($mailflash->accepted($result));
```

## API

### `new MailFlashClient(string $baseUrl, string $apiKey, int $timeoutSeconds = 30)`

### `send(array $payload, ?string $idempotencyKey = null): array`

Returns `['status' => int, 'body' => array|string, 'response' => Response]`.

### `accepted(array $result): bool`

Returns `true` if `$result['status'] === 202`.

### `throwIfFailed(array $result): array`

Returns `$result` unchanged if accepted, otherwise throws `Illuminate\Http\Client\RequestException`.
