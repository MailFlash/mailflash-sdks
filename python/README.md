# MailFlash Python client

Single-file Python client for the [MailFlash](https://mailflash.es) transactional email API.

## Requirements

- Python 3.10+
- `requests` library

```bash
pip install requests
```

## Installation

Copy [`mailflash_client.py`](./mailflash_client.py) into your project. No package manager needed.

## Usage

```python
import os
from mailflash_client import MailFlashClient

client = MailFlashClient(
    'https://mailflash.es',
    api_key=os.environ['MAILFLASH_API_KEY'],
)

result = client.send({
    'from': 'hello@yourdomain.com',
    'to': ['you@example.com'],
    'subject': 'Order confirmed',
    'html': '<p>Thanks for your order!</p>',
}, idempotency_key='order-123')

if client.accepted(result):
    print(result['body'])  # {'id': '...', 'status': 'queued'}
else:
    print('Failed:', result['status'], result['body'])
```

### Named recipients

```python
result = client.send({
    'from': 'hello@yourdomain.com',
    'to': [
        {'email': 'alice@example.com', 'name': 'Alice'},
        'bob@example.com',
    ],
    'subject': 'Hello',
    'html': '<p>Hi!</p>',
})
```

### Raise on failure

```python
from mailflash_client import MailFlashError

try:
    client.raise_for_status(client.send({...}))
except MailFlashError as e:  # subclass of RuntimeError
    print(e.status, e.body)
```

### Attachments

Up to 10 files, 10 MB in total (decoded). For an inline image, set `content_id` (it must contain `@`, e.g. `logo@yourdomain.com`) and reference it from the HTML as `cid:logo@yourdomain.com`.

```python
import base64

with open('invoice.pdf', 'rb') as f:
    pdf_b64 = base64.b64encode(f.read()).decode()

result = client.send({
    'from': 'billing@yourdomain.com',
    'to': ['customer@example.com'],
    'subject': 'Your invoice',
    'html': '<p>Please find your invoice attached.</p>',
    'attachments': [
        {
            'filename': 'invoice.pdf',
            'content': pdf_b64,
            'content_type': 'application/pdf',
        }
    ],
})
```

### Other API methods

```python
stats = client.get_stats(date_from='2026-06-01')
emails = client.list_emails(status='delivered', from_='hello@yourdomain.com', page=2)
email = client.get_email('uuid-here', include_body=True)
events = client.get_email_events('uuid-here')
contacts = client.list_contacts(status='suppressed')
domains = client.list_domains(verified_only=True)

if client.ok(stats):  # any 2xx
    print(stats['body'])
```

`from_` has a trailing underscore because `from` is a Python keyword.

### Reuse the session (recommended for high volume)

```python
import requests

session = requests.Session()
client = MailFlashClient('https://mailflash.es', 'YOUR_API_KEY', session=session)
```

## API

### `MailFlashClient(base_url, api_key, timeout_seconds=30.0, session=None)`

| Param | Type | Description |
|---|---|---|
| `base_url` | `str` | MailFlash base URL, e.g. `https://mailflash.es` |
| `api_key` | `str` | Your project API key |
| `timeout_seconds` | `float` | Request timeout (default 30s) |
| `session` | `requests.Session` | Optional — bring your own session |

### `client.send(payload, *, idempotency_key=None) → SendResult`

Sends the email. Returns `{'status': int, 'body': dict | str}`.

A `status` of `202` means the email was accepted. `status=0` means a network/transport error.

### `client.accepted(result) → bool`

Returns `True` if `result['status'] == 202`.

### `client.ok(result) → bool`

Returns `True` for any 2xx status.

### `client.raise_for_status(result) → SendResult`

Returns `result` unchanged if accepted, otherwise raises `MailFlashError` (a `RuntimeError` subclass with `.status` and `.body`).
